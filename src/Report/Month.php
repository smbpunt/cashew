<?php

namespace App\Report;

/**
 * The reports go month by month: each month is told by its first day, at midnight.
 */
final class Month
{
    public static function firstDayOf(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return $day->modify('first day of this month')->setTime(0, 0);
    }
}
