<?php

namespace App\Recurrence;

use App\Entity\BankAccount;
use App\Entity\RecurringSeries;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Periodicity;
use App\Enum\SeriesStatus;
use App\Repository\RecurringSeriesRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds the transactions that come back: the user's transactions are grouped by account, counterparty and direction;
 * those of a known series join it, and the groups that look recurring are suggested for the user to review.
 */
final readonly class RecurrenceDetector
{
    /**
     * Under it, a group isn't worth suggesting.
     */
    public const int MIN_CONFIDENCE = 60;

    /**
     * How Crédit Agricole names the monthly debit of a deferred debit card: imported as regular when the card
     * statement is missing, it only adds up purchases.
     */
    private const string CARD_SETTLEMENT = 'debit mensuel carte';

    public function __construct(
        private TransactionRepository $transactions,
        private RecurringSeriesRepository $series,
        private CounterpartyNormalizer $normalizer,
        private SeriesAnalyzer $analyzer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Persists the new series and the transactions joining one, and removes the suggestions that no longer hold,
     * without flushing them: the transactions it groups must be flushed already.
     */
    public function detect(User $owner): void
    {
        /** @var array<string, RecurringSeries> $known */
        $known = [];
        foreach ($this->series->findOwned($owner) as $series) {
            $known[self::key($series->getAccount(), $series->getCounterparty(), $series->isIncome())] = $series;
        }

        /** @var array<string, array{account: BankAccount, counterparty: string, income: bool, transactions: list<Transaction>}> $groups */
        $groups = [];
        /** @var array<int, \DateTimeImmutable> $lastDates by account */
        $lastDates = [];
        foreach ($this->transactions->findForRecurrence($owner) as $transaction) {
            $account = $transaction->getAccount();
            $lastDates[spl_object_id($account)] = max($lastDates[spl_object_id($account)] ?? $transaction->getDate(), $transaction->getDate());

            $counterparty = $this->normalizer->normalize($transaction->getLabel());
            if ('' === $counterparty || str_starts_with($counterparty, self::CARD_SETTLEMENT) || 0 === $transaction->getAmount()) {
                continue;
            }

            $income = $transaction->getAmount() > 0;
            $groups[self::key($account, $counterparty, $income)] ??= ['account' => $account, 'counterparty' => $counterparty, 'income' => $income, 'transactions' => []];
            $groups[self::key($account, $counterparty, $income)]['transactions'][] = $transaction;
        }

        foreach ($groups as $key => $group) {
            $series = $known[$key] ?? null;
            if (SeriesStatus::Rejected === $series?->getStatus()) {
                continue;
            }

            $lastDate = $lastDates[spl_object_id($group['account'])];

            if (null === $series) {
                $analysis = $this->analyzer->analyze($group['transactions']);
                if (null === $analysis) {
                    continue;
                }

                // Its purchases aside, a shop may well have a subscription
                $members = self::membersOf($group['transactions'], null, $analysis->typicalAmount, $analysis->periodicity);
                $analysis = $this->analyzer->analyze($members);
                if (!$this->isWorthSuggesting($analysis, $lastDate)) {
                    continue;
                }

                $series = new RecurringSeries(
                    $group['account'],
                    $group['counterparty'],
                    $group['income'],
                    $this->normalizer->name($group['counterparty']),
                    $analysis->periodicity,
                    $analysis->typicalAmount,
                    $analysis->confidence,
                    $analysis->lastSeenOn,
                );
                $this->entityManager->persist($series);
            } else {
                $members = self::membersOf($group['transactions'], $series, $series->getTypicalAmount(), $series->getPeriodicity());
                $analysis = $this->analyzer->analyze($members);

                // No longer recurring, or no longer going on: unless the user confirmed it, it's no longer a suggestion
                if (SeriesStatus::Suggested === $series->getStatus() && !$this->isWorthSuggesting($analysis, $lastDate)) {
                    $this->remove($series, $group['transactions']);
                    continue;
                }

                if (null !== $analysis) {
                    $series->refresh($analysis->periodicity, $analysis->typicalAmount, $analysis->confidence, $analysis->lastSeenOn);
                }
            }

            foreach ($members as $transaction) {
                $transaction->attachTo($series);
            }
        }
    }

    /**
     * @phpstan-assert-if-true !null $analysis
     */
    private function isWorthSuggesting(?SeriesAnalysis $analysis, \DateTimeImmutable $lastDate): bool
    {
        return null !== $analysis && $analysis->confidence >= self::MIN_CONFIDENCE && $analysis->isCurrent($lastDate);
    }

    /**
     * @param list<Transaction> $transactions
     */
    private function remove(RecurringSeries $series, array $transactions): void
    {
        foreach ($transactions as $transaction) {
            if ($transaction->getRecurringSeries() === $series) {
                $transaction->attachTo(null);
            }
        }

        $this->entityManager->remove($series);
    }

    /**
     * The transactions of the group that are the series': those of it already, those at its typical amount, and
     * those on time right after or right before one of them, at a new price or an old one. A purchase from the shop
     * of a subscription is neither.
     *
     * @param list<Transaction> $transactions oldest first
     *
     * @return list<Transaction> oldest first
     */
    private static function membersOf(array $transactions, ?RecurringSeries $series, int $typicalAmount, Periodicity $periodicity): array
    {
        $isMember = $byDay = [];
        foreach ($transactions as $i => $transaction) {
            $attached = $transaction->getRecurringSeries();
            $isMember[$i] = (null !== $series && $attached === $series) || (null === $attached && SeriesAnalyzer::isSimilarAmount($transaction->getAmount(), $typicalAmount));
            $byDay[$transaction->getDate()->format('Y-m-d')][] = $i;
        }

        // Forward for those after one of them, then backward for those before
        foreach ([$byDay, array_reverse($byDay)] as $days) {
            $memberDay = null;
            foreach ($days as $day => $indexes) {
                foreach ($indexes as $i) {
                    if (!$isMember[$i] && null === $transactions[$i]->getRecurringSeries() && null !== $memberDay) {
                        $isMember[$i] = $periodicity->allows((int) new \DateTimeImmutable($memberDay)->diff(new \DateTimeImmutable($day))->days);
                    }
                }
                if (array_any($indexes, static fn (int $i): bool => $isMember[$i])) {
                    $memberDay = $day;
                }
            }
        }

        return array_values(array_filter($transactions, static fn (int $i): bool => $isMember[$i], \ARRAY_FILTER_USE_KEY));
    }

    private static function key(BankAccount $account, string $counterparty, bool $income): string
    {
        return implode("\0", [spl_object_id($account), $counterparty, (int) $income]);
    }
}
