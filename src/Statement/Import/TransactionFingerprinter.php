<?php

namespace App\Statement\Import;

use App\Statement\ParsedTransaction;

/**
 * Identifies a transaction within its account, from what any statement covering its day says of it.
 *
 * Two identical transactions on the same day (two coffees at 3 €) are told apart by their occurrence: their rank
 * among the identical transactions of the statement. Statements cover whole days, so the ranks match from one
 * statement to the next.
 */
final class TransactionFingerprinter
{
    public function fingerprint(ParsedTransaction $transaction, int $occurrence): string
    {
        return hash('sha256', implode("\0", [$this->identity($transaction), $occurrence]));
    }

    /**
     * What identical transactions share.
     */
    public function identity(ParsedTransaction $transaction): string
    {
        // Spacing and case are formatting details, which may change between exports
        $label = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $transaction->label)));

        return implode("\0", [$transaction->date->format('Y-m-d'), $transaction->amount, $transaction->currency, $label]);
    }
}
