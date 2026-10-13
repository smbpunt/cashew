<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How often a recurring series comes back.
 */
enum Periodicity: string implements TranslatableInterface
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Bimonthly = 'bimonthly';
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';
    case Yearly = 'yearly';

    /**
     * Up to how many periods may go by between two occurrences, one or two of them missed, for the next to be on time.
     */
    public const int MAX_PERIODS = 3;

    /**
     * Whether two occurrences that many days apart are on time, give or take the weekends and the length of months.
     */
    public function allows(int $days): bool
    {
        return null !== $this->periodsIn($days);
    }

    /**
     * How many periods went by between two occurrences that many days apart, if they're on time: 1, or more when
     * some were missed; the tolerance grows with the periods.
     */
    public function periodsIn(int $days): ?int
    {
        [$min, $max] = $this->window();

        for ($periods = 1; $periods <= self::MAX_PERIODS; ++$periods) {
            if ($days >= $periods * $min && $days <= $periods * $max) {
                return $periods;
            }
        }

        return null;
    }

    /**
     * The most days one period may last and the next occurrence still be on time.
     */
    public function maxDays(): int
    {
        return $this->window()[1];
    }

    /**
     * The day of the nth occurrence after the anchor one, the anchor's day of the month kept, or the last day of a
     * shorter month: a debit of the 31st comes back on November 30th, and on December 31st.
     */
    public function nth(\DateTimeImmutable $anchor, int $n): \DateTimeImmutable
    {
        [$length, $unit] = match ($this) {
            self::Weekly => [7, 'days'],
            self::Monthly => [1, 'months'],
            self::Bimonthly => [2, 'months'],
            self::Quarterly => [3, 'months'],
            self::Semiannual => [6, 'months'],
            self::Yearly => [12, 'months'],
        };
        if ('days' === $unit) {
            return $anchor->modify(\sprintf('%+d days', $n * $length));
        }

        $month = $anchor->modify('first day of this month')->modify(\sprintf('%+d months', $n * $length));

        return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), min((int) $anchor->format('j'), (int) $month->format('t')));
    }

    public function days(): int
    {
        return match ($this) {
            self::Weekly => 7,
            self::Monthly => 30,
            self::Bimonthly => 61,
            self::Quarterly => 91,
            self::Semiannual => 182,
            self::Yearly => 365,
        };
    }

    /**
     * How many occurrences it takes to tell it from a coincidence.
     */
    public function minOccurrences(): int
    {
        return \in_array($this, [self::Semiannual, self::Yearly], true) ? 2 : 3;
    }

    /**
     * The fewest and most days between two occurrences one period apart.
     *
     * @return array{int, int}
     */
    private function window(): array
    {
        return match ($this) {
            self::Weekly => [6, 8],
            self::Monthly => [26, 35],
            self::Bimonthly => [55, 66],
            self::Quarterly => [85, 97],
            self::Semiannual => [170, 195],
            self::Yearly => [350, 380],
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('recurrence.periodicity.'.$this->value, locale: $locale);
    }
}
