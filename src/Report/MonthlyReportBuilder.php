<?php

namespace App\Report;

use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\TransactionRepository;
use App\Time\Month;
use App\Time\MonthOutOfRangeException;

/**
 * Sums up a month of the user's transactions, dated on the day they were made: a purchase with a deferred debit
 * card counts on the day of the purchase, and the monthly debit that settles it doesn't count. Neither does money
 * moved between the user's accounts, listed all the same.
 */
final readonly class MonthlyReportBuilder
{
    public function __construct(
        private TransactionRepository $transactions,
    ) {
    }

    /**
     * @param Month|null $month null for the last month with transactions
     *
     * @return MonthlyReport|null null when the user has no transactions yet
     *
     * @throws MonthOutOfRangeException when the month is before the first transaction, or after the last one
     */
    public function build(User $owner, ?Month $month = null): ?MonthlyReport
    {
        $range = $this->transactions->findDateRange($owner);
        if (null === $range) {
            return null;
        }

        [$first, $last] = array_map(Month::of(...), $range);
        $month ??= $last;
        if ($month->isBefore($first) || $month->isAfter($last)) {
            throw MonthOutOfRangeException::outside($month, $first, $last);
        }

        $transactions = $this->transactions->findOfMonth($owner, $month);
        $counted = array_values(array_filter($transactions, static fn (Transaction $transaction): bool => $transaction->countsInTotals()));

        $totals = [];
        foreach ($counted as $transaction) {
            $currency = $transaction->getCurrency();
            $totals[$currency] = ($totals[$currency] ?? new MonthlyTotals($currency))->add($transaction->getAmount());
        }
        ksort($totals);

        return new MonthlyReport(
            $month,
            $transactions,
            array_values($totals),
            $month->isAfter($first) ? $month->previous() : null,
            $month->isBefore($last) ? $month->next() : null,
            array_map(static fn (MonthlyTotals $totals): CategoryBreakdown => CategoryBreakdown::of($totals->currency, $counted), array_values($totals)),
        );
    }
}
