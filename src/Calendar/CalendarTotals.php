<?php

namespace App\Calendar;

/**
 * What the calendar shows going out in one currency over a month, in minor units (cents), negative.
 */
final readonly class CalendarTotals
{
    public function __construct(
        public string $currency,
        public int $total = 0,
        /**
         * The part of it expected, not known yet.
         */
        public int $expected = 0,
    ) {
    }

    public function add(CalendarEntry $entry): self
    {
        return new self($this->currency, $this->total + $entry->amount, $this->expected + ($entry->isExpected() ? $entry->amount : 0));
    }
}
