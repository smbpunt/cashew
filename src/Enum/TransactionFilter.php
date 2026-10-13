<?php

namespace App\Enum;

use App\Entity\Transaction;

/**
 * Which of the transactions of a month the dashboard lists.
 */
enum TransactionFilter: string
{
    case All = 'all';

    /**
     * Those the user didn't tell what they are yet: of no series they confirmed, from a counterparty they didn't name,
     * filed in no category, of their own, of their series or of their counterparty; the transfers the import filed
     * included. Money moved between their accounts aside: they know what it is.
     */
    case Unidentified = 'unidentified';

    public function matches(Transaction $transaction): bool
    {
        return match ($this) {
            self::All => true,
            self::Unidentified => !$transaction->isIdentified() && !$transaction->isInternalTransfer(),
        };
    }

    /**
     * @param list<Transaction> $transactions
     *
     * @return list<Transaction> in the same order
     */
    public function filter(array $transactions): array
    {
        return array_values(array_filter($transactions, $this->matches(...)));
    }
}
