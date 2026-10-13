<?php

namespace App\Enum;

/**
 * What the calendar shows of a month's spending.
 */
enum CalendarFilter: string
{
    /**
     * What the user confirmed is recurring, those expected included.
     */
    case Recurring = 'recurring';

    /**
     * Every debit known, internal transfers aside, and the recurring ones expected.
     */
    case All = 'all';
}
