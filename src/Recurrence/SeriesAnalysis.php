<?php

namespace App\Recurrence;

use App\Enum\Periodicity;

/**
 * How a group of transactions of the same counterparty comes back.
 */
final readonly class SeriesAnalysis
{
    public function __construct(
        public Periodicity $periodicity,
        /**
         * The median amount of the last three occurrences, for it to follow a new price; in minor units (cents).
         */
        public int $typicalAmount,
        /**
         * From 0 to 100.
         */
        public int $confidence,
        public \DateTimeImmutable $lastSeenOn,
    ) {
    }

    /**
     * Whether it still goes on: its last occurrence isn't two periods older than the last transaction of the account.
     */
    public function isCurrent(\DateTimeImmutable $lastTransactionDate): bool
    {
        return $this->lastSeenOn->modify(\sprintf('+%d days', 2 * $this->periodicity->days())) >= $lastTransactionDate;
    }
}
