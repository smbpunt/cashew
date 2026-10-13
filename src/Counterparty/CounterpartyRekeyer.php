<?php

namespace App\Counterparty;

use App\Entity\Counterparty;
use App\Entity\RecurringSeries;
use App\Entity\User;
use App\Enum\SeriesStatus;
use App\Repository\CounterpartyRepository;
use App\Repository\RecurringSeriesRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tells the counterparty of the user's transactions again, once CounterpartyNormalizer tells them otherwise: those
 * that now tell the same one are merged, the name and the category the user gave either kept, and their series follow
 * them. Of two series that end up the same, the one the user reviewed stays.
 */
final readonly class CounterpartyRekeyer
{
    public function __construct(
        private CounterpartyNormalizer $normalizer,
        private TransactionRepository $transactions,
        private CounterpartyRepository $counterparties,
        private RecurringSeriesRepository $series,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Flushes the series it removes before moving the others onto their place, then persists and removes the rest
     * without flushing.
     *
     * @return int how many transactions changed counterparty
     */
    public function rekey(User $owner): int
    {
        /** @var array<string, Counterparty> $byKey */
        $byKey = [];
        foreach ($this->counterparties->findBy(['owner' => $owner]) as $counterparty) {
            $byKey[$counterparty->getKey()] = $counterparty;
        }

        $moved = 0;
        /** @var array<int, true> $used by object id */
        $used = [];
        /** @var array<int, Counterparty> $ofSeries the counterparty of each series' transactions, by series object id */
        $ofSeries = [];
        foreach ($this->transactions->findOwned($owner) as $transaction) {
            $key = $this->normalizer->normalize($transaction->getLabel());
            $old = $transaction->getCounterparty();
            $new = '' === $key ? null : ($byKey[$key] ??= $this->create($owner, $key));

            if ($new !== $old) {
                if (null !== $old && null !== $new) {
                    $new->absorb($old);
                }
                $transaction->assignTo($new);
                ++$moved;
            }

            if (null !== $new) {
                $used[spl_object_id($new)] = true;
                if (null !== $series = $transaction->getRecurringSeries()) {
                    $ofSeries[spl_object_id($series)] = $new;
                }
            }
        }

        foreach ($this->moveSeries($owner, $ofSeries) as $series) {
            $used[spl_object_id($series->getCounterparty())] = true;
        }

        foreach ($byKey as $counterparty) {
            if (!isset($used[spl_object_id($counterparty)])) {
                $this->entityManager->remove($counterparty);
            }
        }

        return $moved;
    }

    /**
     * @param array<int, Counterparty> $counterparties by series object id
     *
     * @return list<RecurringSeries> those that stay
     */
    private function moveSeries(User $owner, array $counterparties): array
    {
        /** @var array<string, array{RecurringSeries, Counterparty}> $staying by account, counterparty, direction and contract */
        $staying = [];
        foreach ($this->series->findOwned($owner) as $series) {
            $counterparty = $counterparties[spl_object_id($series)] ?? $series->getCounterparty();
            $identity = implode("\0", [spl_object_id($series->getAccount()), spl_object_id($counterparty), (int) $series->isIncome(), $series->getReference()]);

            $other = $staying[$identity][0] ?? null;
            if (null === $other) {
                $staying[$identity] = [$series, $counterparty];
            } elseif (self::prevails($series, $other)) {
                $this->remove($other);
                $staying[$identity] = [$series, $counterparty];
            } else {
                $this->remove($series);
            }
        }

        // Gone before the others take their place: the database checks each one is the only one of its counterparty
        $this->entityManager->flush();
        foreach ($staying as [$series, $counterparty]) {
            $series->assignTo($counterparty);
        }

        return array_column($staying, 0);
    }

    /**
     * Whether it stays rather than the other: the user reviewed it, and not the other.
     */
    private static function prevails(RecurringSeries $series, RecurringSeries $other): bool
    {
        return SeriesStatus::Suggested !== $series->getStatus() && SeriesStatus::Suggested === $other->getStatus();
    }

    /**
     * Its transactions join no series, until the next detection finds theirs.
     */
    private function remove(RecurringSeries $series): void
    {
        foreach ($this->transactions->findBy(['recurringSeries' => $series]) as $transaction) {
            $transaction->attachTo(null);
        }
        $this->entityManager->remove($series);
    }

    private function create(User $owner, string $key): Counterparty
    {
        $counterparty = new Counterparty($owner, $key);
        $this->entityManager->persist($counterparty);

        return $counterparty;
    }
}
