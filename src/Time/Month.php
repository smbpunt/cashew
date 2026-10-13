<?php

namespace App\Time;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A month of the calendar, as the reports and the calendar go: "2026-09" in a query, September 2026 on a page.
 */
#[Exclude]
final readonly class Month implements \Stringable
{
    private function __construct(
        public int $year,
        /**
         * 1 to 12.
         */
        public int $month,
    ) {
    }

    /**
     * The month the day is in.
     */
    public static function of(\DateTimeInterface $day): self
    {
        return new self((int) $day->format('Y'), (int) $day->format('n'));
    }

    /**
     * @param string $key as "2026-09"
     *
     * @throws \InvalidArgumentException when it isn't one
     */
    public static function fromKey(string $key): self
    {
        if (!preg_match('/^(?<year>\d{4})-(?<month>0[1-9]|1[0-2])$/', $key, $matches)) {
            throw new \InvalidArgumentException(\sprintf('No month "%s": one is told as "2026-09".', $key));
        }

        return new self((int) $matches['year'], (int) $matches['month']);
    }

    /**
     * The months from one to the other, both included, oldest first; none when the first comes after the last.
     *
     * @return list<self>
     */
    public static function range(self $first, self $last): array
    {
        $months = [];
        for ($month = $first; !$month->isAfter($last); $month = $month->next()) {
            $months[] = $month;
        }

        return $months;
    }

    /**
     * As "2026-09": the month in a query, or what amounts are keyed by.
     */
    public function key(): string
    {
        return \sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function __toString(): string
    {
        return $this->key();
    }

    /**
     * At midnight.
     */
    public function firstDay(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->key().'-01');
    }

    /**
     * At midnight.
     */
    public function lastDay(): \DateTimeImmutable
    {
        return $this->firstDay()->modify('last day of this month');
    }

    public function plus(int $months): self
    {
        $index = $this->year * 12 + $this->month - 1 + $months;

        return new self(intdiv($index, 12), $index % 12 + 1);
    }

    public function next(): self
    {
        return $this->plus(1);
    }

    public function previous(): self
    {
        return $this->plus(-1);
    }

    /**
     * How many months there are from it to the other: 0 to itself, negative to an earlier one.
     */
    public function monthsUntil(self $other): int
    {
        return ($other->year - $this->year) * 12 + $other->month - $this->month;
    }

    public function contains(\DateTimeInterface $day): bool
    {
        return $this->equals(self::of($day));
    }

    public function equals(self $other): bool
    {
        return 0 === $this->monthsUntil($other);
    }

    public function isBefore(self $other): bool
    {
        return $this->monthsUntil($other) > 0;
    }

    public function isAfter(self $other): bool
    {
        return $this->monthsUntil($other) < 0;
    }

    public function earliest(self $other): self
    {
        return $other->isBefore($this) ? $other : $this;
    }

    public function latest(self $other): self
    {
        return $other->isAfter($this) ? $other : $this;
    }
}
