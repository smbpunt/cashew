<?php

namespace App\Enum;

/**
 * How often a recurring series comes back.
 */
enum Periodicity: string
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
        [$min, $max] = match ($this) {
            self::Weekly => [6, 8],
            self::Monthly => [26, 35],
            self::Bimonthly => [55, 66],
            self::Quarterly => [85, 97],
            self::Semiannual => [170, 195],
            self::Yearly => [350, 380],
        };

        for ($periods = 1; $periods <= self::MAX_PERIODS; ++$periods) {
            if ($days >= $periods * $min && $days <= $periods * $max) {
                return $periods;
            }
        }

        return null;
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
}
