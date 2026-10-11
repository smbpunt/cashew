<?php

namespace App\Statement\Import;

use Psr\Clock\ClockInterface;

/**
 * Only whole months are imported: a transaction is imported once its month is over, so that the month in
 * progress never looks complete. A statement uploaded on October 11th imports up to September 30th.
 */
final readonly class CutoffPolicy
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    /**
     * The last day imported.
     */
    public function cutoffDate(): \DateTimeImmutable
    {
        return $this->clock->now()->modify('last day of previous month')->setTime(0, 0);
    }

    /**
     * The day the month in progress can be imported: once it's over.
     */
    public function nextImportDate(): \DateTimeImmutable
    {
        return $this->clock->now()->modify('first day of next month')->setTime(0, 0);
    }

    public function allows(\DateTimeImmutable $date): bool
    {
        // Compared as calendar days, whatever the time zones
        return $date->format('Y-m-d') <= $this->cutoffDate()->format('Y-m-d');
    }
}
