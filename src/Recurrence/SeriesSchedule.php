<?php

namespace App\Recurrence;

use App\Entity\RecurringSeries;
use App\Time\Month;

/**
 * When a series comes back next, and whether it still does.
 *
 * Told against the last day known of its account, the day of its last imported transaction, rather than today: only
 * whole months are imported, and an account not imported for a while doesn't make its subscriptions look stopped.
 */
final readonly class SeriesSchedule
{
    /**
     * The last day known of its account: that of its last transaction.
     *
     * @param array<int, \DateTimeImmutable> $lastDates the day of the last transaction of each account, by account id
     */
    public function knownUntil(RecurringSeries $series, array $lastDates): \DateTimeImmutable
    {
        return $lastDates[$series->getAccount()->getId()] ?? $series->getLastSeenOn();
    }

    /**
     * Whether an occurrence it was expected to have didn't come, give or take the length of months, since its last
     * one, or since the user said it still goes on. One the user said ended no longer is.
     */
    public function seemsEnded(RecurringSeries $series, \DateTimeImmutable $knownUntil): bool
    {
        if ($series->isEnded()) {
            return false;
        }

        $since = max($series->getLastSeenOn(), $series->getStillActiveOn() ?? $series->getLastSeenOn());

        return self::daysBetween($since, $knownUntil) > $series->getPeriodicity()->maxDays();
    }

    /**
     * The days it's expected within the month, after the last day known: none once it ended, or seems to have.
     *
     * @return list<\DateTimeImmutable>
     */
    public function occurrencesIn(RecurringSeries $series, Month $month, \DateTimeImmutable $knownUntil): array
    {
        if ($series->isEnded() || $this->seemsEnded($series, $knownUntil)) {
            return [];
        }

        $start = $month->firstDay()->format('Y-m-d');
        $end = $month->lastDay()->format('Y-m-d');
        $after = $knownUntil->format('Y-m-d');

        $occurrences = [];
        for ($n = 1; ($day = $series->getPeriodicity()->nth($series->getLastSeenOn(), $n))->format('Y-m-d') <= $end; ++$n) {
            if ($day->format('Y-m-d') >= $start && $day->format('Y-m-d') > $after) {
                $occurrences[] = $day;
            }
        }

        return $occurrences;
    }

    private static function daysBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $from->setTime(0, 0)->diff($to->setTime(0, 0))->format('%r%a');
    }
}
