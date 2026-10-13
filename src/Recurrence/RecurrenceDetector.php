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
 * Finds the transactions that come back: the user's transactions are grouped by account, counterparty and direction,
 * then by contract when the counterparty has several at once; those of a known series join it, and the groups that
 * look recurring are suggested for the user to review.
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
        private ReferenceSplitter $splitter,
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
            $known[self::key($series->getAccount(), $series->getCounterparty(), $series->isIncome(), $series->getReference())] = $series;
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
            foreach ($this->contractsOf($group['transactions'], $known[$key] ?? null) as $reference => $transactions) {
                $this->detectIn($group['account'], $group['counterparty'], $group['income'], (string) $reference, $transactions, $known, $lastDates[spl_object_id($group['account'])]);
            }
        }
    }

    /**
     * The transactions of a counterparty by contract. Unless the user reviewed them as one series already: then
     * they stay one, as the user took them. A suggestion of them as one gives way to those of each contract.
     *
     * @param list<Transaction> $transactions
     *
     * @return array<string, list<Transaction>>
     */
    private function contractsOf(array $transactions, ?RecurringSeries $whole): array
    {
        if (null !== $whole && SeriesStatus::Suggested !== $whole->getStatus()) {
            return ['' => $transactions];
        }

        $contracts = $this->splitter->split($transactions);
        if (null !== $whole && [''] !== array_keys($contracts)) {
            // Before the contracts take its transactions: those of a series join no other
            $this->remove($whole, $transactions);
        }

        return $contracts;
    }

    /**
     * @param list<Transaction>              $transactions of the same account, counterparty, direction and contract, oldest first
     * @param array<string, RecurringSeries> $known
     */
    private function detectIn(BankAccount $account, string $counterparty, bool $income, string $reference, array $transactions, array $known, \DateTimeImmutable $lastDate): void
    {
        $series = $known[self::key($account, $counterparty, $income, $reference)] ?? null;
        // The suggestion of the whole counterparty, removed for those of its contracts
        if (null !== $series && !$this->entityManager->contains($series)) {
            $series = null;
        }
        if (SeriesStatus::Rejected === $series?->getStatus()) {
            return;
        }

        if (null === $series) {
            $analysis = $this->analyzer->analyze($transactions);
            if (null === $analysis) {
                return;
            }

            // Its purchases aside, a shop may well have a subscription
            $members = self::membersOf($transactions, null, $analysis->typicalAmount, $analysis->periodicity);
            $analysis = $this->analyzer->analyze($members);
            if (!$this->isWorthSuggesting($analysis, $lastDate)) {
                return;
            }

            $series = new RecurringSeries(
                $account,
                $counterparty,
                $income,
                $this->normalizer->name($counterparty, $reference),
                $analysis->periodicity,
                $analysis->typicalAmount,
                $analysis->confidence,
                $analysis->lastSeenOn,
                $reference,
            );
            $this->entityManager->persist($series);
        } else {
            $members = self::membersOf($transactions, $series, $series->getTypicalAmount(), $series->getPeriodicity());
            $analysis = $this->analyzer->analyze($members);

            // No longer recurring, or no longer going on: unless the user confirmed it, it's no longer a suggestion
            if (SeriesStatus::Suggested === $series->getStatus() && !$this->isWorthSuggesting($analysis, $lastDate)) {
                $this->remove($series, $transactions);

                return;
            }

            if (null !== $analysis) {
                $series->refresh($analysis->periodicity, $analysis->typicalAmount, $analysis->confidence, $analysis->lastSeenOn);
            }
        }

        foreach ($members as $transaction) {
            $transaction->attachTo($series);
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

    private static function key(BankAccount $account, string $counterparty, bool $income, string $reference = ''): string
    {
        return implode("\0", [spl_object_id($account), $counterparty, (int) $income, $reference]);
    }
}
