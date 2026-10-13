<?php

namespace App\Dashboard;

use App\Enum\TransactionFilter;
use App\Enum\TransactionListMode;
use App\Enum\TransactionSort;

/**
 * How the dashboard is asked to list the transactions of a month: null for as the last time.
 */
final readonly class TransactionListQuery
{
    public function __construct(
        public ?TransactionListMode $mode = null,
        public ?TransactionSort $sort = null,
        public ?TransactionFilter $show = null,
    ) {
    }
}
