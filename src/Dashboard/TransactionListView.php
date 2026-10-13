<?php

namespace App\Dashboard;

use App\Entity\Transaction;
use App\Enum\TransactionFilter;
use App\Enum\TransactionListMode;
use App\Enum\TransactionSort;

/**
 * How the dashboard lists the transactions of a month.
 */
final readonly class TransactionListView
{
    public function __construct(
        public TransactionListMode $mode = TransactionListMode::Simple,
        public TransactionSort $sort = TransactionSort::Date,
        public TransactionFilter $show = TransactionFilter::All,
    ) {
    }

    /**
     * Those it shows, in its order: the totals of the month count them all.
     *
     * @param list<Transaction> $transactions most recent first
     *
     * @return list<Transaction>
     */
    public function apply(array $transactions): array
    {
        return $this->sort->sort($this->show->filter($transactions));
    }
}
