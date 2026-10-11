<?php

namespace App\Report;

use App\Entity\Transaction;

final readonly class MonthlyReport
{
    public function __construct(
        /**
         * The first day of the month.
         */
        public \DateTimeImmutable $month,
        /**
         * Most recent first.
         *
         * @var list<Transaction>
         */
        public array $transactions,
        /**
         * One per currency of the transactions.
         *
         * @var list<MonthlyTotals>
         */
        public array $totals,
        /**
         * Null when the month is the first with transactions.
         */
        public ?\DateTimeImmutable $previousMonth,
        /**
         * Null when the month is the last with transactions.
         */
        public ?\DateTimeImmutable $nextMonth,
    ) {
    }
}
