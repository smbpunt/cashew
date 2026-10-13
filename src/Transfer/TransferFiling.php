<?php

namespace App\Transfer;

use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\CategoryRole;

/**
 * The "Transfers" category is what the import files a transfer in when it knows no better: what the user tells of a
 * transaction otherwise, of its series or of its counterparty, wins over it.
 */
final readonly class TransferFiling
{
    /**
     * Files it in the transfers category, unless the user told what it is already: the rent paid by transfer stays in
     * "Rent", the salary stays a salary.
     */
    public function file(Transaction $transaction, Category $transfers): void
    {
        if (!$transaction->isRecurring() && null === $transaction->getEffectiveCategory()) {
            $transaction->categorize($transfers);
        }
    }

    /**
     * Takes those filed in transfers out of it, for them to take the category the user just gave their series or
     * their counterparty: the rent paid by transfer goes to "Rent". Not the money moved between the user's accounts:
     * it stays out of the totals.
     *
     * @param iterable<Transaction> $transactions
     */
    public function release(iterable $transactions): void
    {
        foreach ($transactions as $transaction) {
            if (CategoryRole::Transfers === $transaction->getCategory()?->getRole() && !$transaction->isInternalTransfer()) {
                $transaction->categorize(null);
            }
        }
    }
}
