<?php

namespace App\Report;

use App\Entity\Transaction;
use App\Time\Month;

final readonly class MonthlyReport
{
    public function __construct(
        public Month $month,
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
        public ?Month $previousMonth,
        /**
         * Null when the month is the last with transactions.
         */
        public ?Month $nextMonth,
        /**
         * One per currency of the transactions, like the totals.
         *
         * @var list<CategoryBreakdown>
         */
        public array $categories,
    ) {
    }
}
