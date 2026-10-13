<?php

namespace App\Report;

use App\Entity\Transaction;
use App\Repository\TransactionRepository;
use App\Time\Month;

/**
 * Finds the other transactions of a transaction's counterparty, and sums them up month by month: over the twelve months
 * up to the last with transactions, or fewer since the first of them, those around the transaction when it's older.
 */
final readonly class CounterpartyHistoryBuilder
{
    private const int MONTHS = 12;

    public function __construct(
        private TransactionRepository $transactions,
    ) {
    }

    public function build(Transaction $transaction): CounterpartyHistory
    {
        $occurrences = $this->occurrencesOf($transaction);
        // A deferred card settlement, or an amount of zero: there's none to compare it with
        if (!\in_array($transaction, $occurrences, true)) {
            $occurrences = [$transaction];
        }

        $range = $this->transactions->findDateRange($transaction->getOwner());
        $month = Month::of($transaction->getDate());
        $last = $month->latest(Month::of($range[1] ?? $transaction->getDate()));
        $first = Month::of(array_last($occurrences)->getDate());

        $start = $first->latest($last->plus(1 - self::MONTHS));
        if ($month->isBefore($start)) {
            $start = $month;
            $last = $last->earliest($month->plus(self::MONTHS - 1));
        }

        $months = Month::range($start, $last);
        $amounts = array_fill_keys(array_map(static fn (Month $month): string => $month->key(), $months), 0);
        foreach ($occurrences as $occurrence) {
            $key = Month::of($occurrence->getDate())->key();
            if (isset($amounts[$key])) {
                $amounts[$key] += $occurrence->getAmount();
            }
        }

        return new CounterpartyHistory($transaction, $occurrences, $months, $amounts);
    }

    /**
     * Those of its series, once the user confirmed it; or else those of its counterparty, in the same direction and
     * currency.
     *
     * @return list<Transaction> most recent first
     */
    private function occurrencesOf(Transaction $transaction): array
    {
        $series = $transaction->getRecurringSeries();
        if (true === $series?->isConfirmed()) {
            return $this->transactions->findOfSeries($series);
        }

        $counterparty = $transaction->getCounterparty();
        if (null === $counterparty || 0 === $transaction->getAmount()) {
            return [$transaction];
        }

        return $this->transactions->findOfCounterparty($counterparty, $transaction->getCurrency(), $transaction->getAmount() > 0);
    }
}
