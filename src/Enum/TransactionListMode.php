<?php

namespace App\Enum;

/**
 * How the dashboard lists the transactions of a month.
 */
enum TransactionListMode: string
{
    /**
     * The date, the label and the amount.
     */
    case Simple = 'simple';

    /**
     * Also the whole label, the account, the bank, the category and the kind of transaction.
     */
    case Detailed = 'detailed';
}
