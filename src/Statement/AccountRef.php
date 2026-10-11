<?php

namespace App\Statement;

/**
 * An account as a statement names it.
 */
final readonly class AccountRef
{
    public function __construct(
        /**
         * Identifies the account within the bank's statements (account number, account name...): it must stay the same from one statement to the next.
         */
        public string $externalId,
        public string $label,
        /**
         * ISO 4217 code.
         */
        public string $currency,
    ) {
    }
}
