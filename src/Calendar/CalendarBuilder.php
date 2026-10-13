<?php

namespace App\Calendar;

use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\CalendarFilter;
use App\Recurrence\SeriesSchedule;
use App\Repository\RecurringSeriesRepository;
use App\Repository\TransactionRepository;
use App\Time\Month;
use App\Time\MonthOutOfRangeException;
use Psr\Clock\ClockInterface;

/**
 * Lays out a month of the user's debits day by day: the transactions known, and the occurrences the recurring series
 * that still go on are expected to have after them. Money moved between the user's accounts isn't spending.
 */
final readonly class CalendarBuilder
{
    /**
     * How many months after the one in progress the calendar goes.
     */
    public const int MONTHS_AHEAD = 12;

    public function __construct(
        private TransactionRepository $transactions,
        private RecurringSeriesRepository $series,
        private SeriesSchedule $schedule,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param Month|null $month null for the month in progress
     *
     * @return MonthCalendar|null null when the user has no transactions yet
     *
     * @throws MonthOutOfRangeException when the month is before the first transaction, or too far ahead
     */
    public function build(User $owner, ?Month $month = null, CalendarFilter $filter = CalendarFilter::Recurring): ?MonthCalendar
    {
        $range = $this->transactions->findDateRange($owner);
        if (null === $range) {
            return null;
        }

        $today = $this->clock->now()->setTime(0, 0);
        $current = Month::of($today);
        $first = Month::of($range[0]);
        $last = $current->plus(self::MONTHS_AHEAD);
        $month ??= $current;
        if ($month->isBefore($first) || $month->isAfter($last)) {
            throw MonthOutOfRangeException::outside($month, $first, $last);
        }

        $entries = array_map(CalendarEntry::of(...), array_values(array_filter(
            $this->transactions->findOfMonth($owner, $month),
            static fn (Transaction $transaction): bool => $transaction->getAmount() < 0
                && $transaction->countsInTotals()
                && (CalendarFilter::All === $filter || $transaction->isRecurring()),
        )));

        $lastDates = $this->transactions->findLastDates($owner);
        $toCheck = $ended = [];
        foreach ($this->series->findConfirmedSpending($owner) as $series) {
            $knownUntil = $this->schedule->knownUntil($series, $lastDates);
            if ($series->isEnded()) {
                $ended[] = $series;
            } elseif ($this->schedule->seemsEnded($series, $knownUntil)) {
                $toCheck[] = $series;
            }

            foreach ($this->schedule->occurrencesIn($series, $month, $knownUntil) as $day) {
                $entries[] = CalendarEntry::expectedOf($series, $day);
            }
        }

        usort($entries, static fn (CalendarEntry $a, CalendarEntry $b): int => [$a->date->format('Y-m-d'), $a->amount, $a->name] <=> [$b->date->format('Y-m-d'), $b->amount, $b->name]);

        $totals = [];
        foreach ($entries as $entry) {
            $totals[$entry->currency] = ($totals[$entry->currency] ?? new CalendarTotals($entry->currency))->add($entry);
        }
        ksort($totals);

        $upcoming = !$month->isBefore($current);

        return new MonthCalendar(
            $month,
            $filter,
            $today,
            self::weeksOf($month),
            $entries,
            array_values($totals),
            $month->isAfter($first) ? $month->previous() : null,
            $month->isBefore($last) ? $month->next() : null,
            $upcoming ? $toCheck : [],
            $upcoming ? $ended : [],
        );
    }

    /**
     * @return list<list<\DateTimeImmutable>>
     */
    private static function weeksOf(Month $month): array
    {
        $first = $month->firstDay();
        $day = $first->modify(\sprintf('-%d days', (int) $first->format('N') - 1));
        $end = $month->lastDay();

        $weeks = [];
        while ($day <= $end) {
            $week = [];
            for ($i = 0; $i < 7; ++$i) {
                $week[] = $day;
                $day = $day->modify('+1 day');
            }
            $weeks[] = $week;
        }

        return $weeks;
    }
}
