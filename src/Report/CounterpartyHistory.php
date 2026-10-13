<?php

namespace App\Report;

use App\Entity\Transaction;
use App\Time\Month;

/**
 * A transaction among the others of its counterparty: those of its series once the user confirmed it, or else those
 * whose label tells the same counterparty, in the same direction and currency, on any of the user's accounts.
 */
final readonly class CounterpartyHistory
{
    public function __construct(
        public Transaction $transaction,
        /**
         * Most recent first, the transaction among them.
         *
         * @var non-empty-list<Transaction>
         */
        public array $occurrences,
        /**
         * Oldest first: twelve at most, since the first occurrence, up to the last month with transactions; that of the
         * transaction among them.
         *
         * @var list<Month>
         */
        public array $months,
        /**
         * In minor units (cents), negative for debits: by month key ("2026-09"), every month there.
         *
         * @var array<string, int>
         */
        public array $amounts,
    ) {
    }

    /**
     * That of the transaction.
     */
    public function getMonth(): Month
    {
        return Month::of($this->transaction->getDate());
    }

    public function getCurrency(): string
    {
        return $this->transaction->getCurrency();
    }

    /**
     * In minor units (cents), negative for debits.
     */
    public function getTotal(): int
    {
        return array_sum(array_map(static fn (Transaction $transaction): int => $transaction->getAmount(), $this->occurrences));
    }

    public function getCount(): int
    {
        return \count($this->occurrences);
    }

    /**
     * What an occurrence comes to, on average.
     */
    public function getAverage(): int
    {
        return (int) round($this->getTotal() / $this->getCount());
    }

    public function getFirst(): Transaction
    {
        return array_last($this->occurrences);
    }

    public function getLast(): Transaction
    {
        return array_first($this->occurrences);
    }

    /**
     * The months from the first occurrence to the last, both included.
     */
    public function getPeriodMonths(): int
    {
        return Month::of($this->getFirst()->getDate())->monthsUntil(Month::of($this->getLast()->getDate())) + 1;
    }

    /**
     * What it comes to in a month, on average over the period.
     */
    public function getMonthlyAverage(): int
    {
        return (int) round($this->getTotal() / $this->getPeriodMonths());
    }

    /**
     * The occurrence of the largest amount, the earliest of them.
     */
    public function getLargest(): Transaction
    {
        return $this->earliestOf(static fn (Transaction $transaction, Transaction $other): bool => abs($transaction->getAmount()) > abs($other->getAmount()));
    }

    /**
     * The occurrence of the smallest amount, the earliest of them.
     */
    public function getSmallest(): Transaction
    {
        return $this->earliestOf(static fn (Transaction $transaction, Transaction $other): bool => abs($transaction->getAmount()) < abs($other->getAmount()));
    }

    /**
     * The largest amount of a month, for the bars to be drawn to scale.
     */
    public function getMaxAmount(): int
    {
        return max(1, ...array_map(abs(...), array_values($this->amounts)));
    }

    /**
     * @param \Closure(Transaction, Transaction): bool $beats whether the first wins over the other
     */
    private function earliestOf(\Closure $beats): Transaction
    {
        $earliest = $this->getFirst();
        foreach (array_reverse($this->occurrences) as $transaction) {
            if ($beats($transaction, $earliest)) {
                $earliest = $transaction;
            }
        }

        return $earliest;
    }
}
