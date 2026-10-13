<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the calendar shows of a month's spending.
 */
enum CalendarFilter: string implements TranslatableInterface
{
    /**
     * What the user confirmed is recurring, those expected included.
     */
    case Recurring = 'recurring';

    /**
     * Every debit known, internal transfers aside, and the recurring ones expected.
     */
    case All = 'all';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('calendar.filter.'.$this->value, locale: $locale);
    }
}
