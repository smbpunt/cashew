<?php

namespace App\Statement\Reader;

final readonly class Money
{
    public function __construct(
        /**
         * In minor units (cents).
         */
        public int $amount,
        /**
         * ISO 4217 code, when the amount spelled its currency out.
         */
        public ?string $currency = null,
    ) {
    }
}
