<?php

namespace App\Recurrence;

use App\Entity\Transaction;

/**
 * A transaction of a confirmed series whose amount isn't that of the previous occurrence: a price rise, a new instalment...
 */
final readonly class AmountChange
{
    public function __construct(
        public Transaction $transaction,
        /**
         * In minor units (cents).
         */
        public int $previousAmount,
        public \DateTimeImmutable $previousDate,
    ) {
    }
}
