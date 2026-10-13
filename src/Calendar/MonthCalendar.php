<?php

namespace App\Calendar;

use App\Entity\RecurringSeries;
use App\Enum\CalendarFilter;

final readonly class MonthCalendar
{
    public function __construct(
        /**
         * The first day of the month.
         */
        public \DateTimeImmutable $month,
        public CalendarFilter $filter,
        public \DateTimeImmutable $today,
        /**
         * The weeks the month spans, Monday to Sunday, with the days of the months around.
         *
         * @var list<list<\DateTimeImmutable>>
         */
        public array $weeks,
        /**
         * Day by day, the largest debit first.
         *
         * @var list<CalendarEntry>
         */
        public array $entries,
        /**
         * One per currency of the entries.
         *
         * @var list<CalendarTotals>
         */
        public array $totals,
        /**
         * Null when the month is the first with transactions.
         */
        public ?\DateTimeImmutable $previousMonth,
        /**
         * Null when the month is the last one shown ahead.
         */
        public ?\DateTimeImmutable $nextMonth,
        /**
         * The series an occurrence of went missing, for the user to tell whether they ended; only from the month in
         * progress on.
         *
         * @var list<RecurringSeries>
         */
        public array $toCheck,
        /**
         * The series the user said ended, to take it back; only from the month in progress on.
         *
         * @var list<RecurringSeries>
         */
        public array $ended,
    ) {
    }

    /**
     * @return list<CalendarEntry>
     */
    public function entriesOn(\DateTimeImmutable $day): array
    {
        return array_values(array_filter($this->entries, static fn (CalendarEntry $entry): bool => $entry->date->format('Y-m-d') === $day->format('Y-m-d')));
    }

    public function isInMonth(\DateTimeImmutable $day): bool
    {
        return $day->format('Y-m') === $this->month->format('Y-m');
    }

    public function isToday(\DateTimeImmutable $day): bool
    {
        return $day->format('Y-m-d') === $this->today->format('Y-m-d');
    }

    public function hasExpected(): bool
    {
        return array_any($this->entries, static fn (CalendarEntry $entry): bool => $entry->isExpected());
    }
}
