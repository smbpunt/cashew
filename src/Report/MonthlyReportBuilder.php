<?php

namespace App\Report;

use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\TransactionRepository;

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
     * @param \DateTimeImmutable|null $month any day of the month; null for the last month with transactions
     *
     * @return MonthlyReport|null null when the user has no transactions yet
     *
     * @throws \OutOfRangeException when the month is before the first transaction, or after the last one
     */
    public function build(User $owner, ?\DateTimeImmutable $month = null): ?MonthlyReport
    {
        $range = $this->transactions->findDateRange($owner);
        if (null === $range) {
            return null;
        }

        [$first, $last] = array_map(Month::firstDayOf(...), $range);
        $month = Month::firstDayOf($month ?? $last);
        if ($month < $first || $month > $last) {
            throw new \OutOfRangeException(\sprintf('No transactions before %s or after %s.', $first->format('Y-m'), $last->format('Y-m')));
        }

        $transactions = $this->transactions->findOfMonth($owner, $month);
        $counted = array_values(array_filter($transactions, static fn (Transaction $transaction): bool => !$transaction->isInternalTransfer()));

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
            $month > $first ? $month->modify('-1 month') : null,
            $month < $last ? $month->modify('+1 month') : null,
            array_map(static fn (MonthlyTotals $totals): CategoryBreakdown => CategoryBreakdown::of($totals->currency, $counted), array_values($totals)),
        );
    }
}
