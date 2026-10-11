<?php

namespace App\Statement;

use App\Enum\TransactionKind;

final readonly class ParsedTransaction
{
    public function __construct(
        public AccountRef $account,
        public \DateTimeImmutable $date,
        /**
         * As the bank wrote it, one line per line of the statement.
         */
        public string $label,
        /**
         * In minor units (cents), negative for a debit.
         */
        public int $amount,
        /**
         * ISO 4217 code.
         */
        public string $currency,
        public TransactionKind $kind = TransactionKind::Regular,
        public ?string $bankCategory = null,
    ) {
    }
}
