<?php

namespace App\Calendar;

use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\CalendarFilter;
use App\Recurrence\SeriesSchedule;
use App\Report\Month;
use App\Repository\RecurringSeriesRepository;
use App\Repository\TransactionRepository;
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
     * @param \DateTimeImmutable|null $month any day of the month; null for the month in progress
     *
     * @return MonthCalendar|null null when the user has no transactions yet
     *
     * @throws \OutOfRangeException when the month is before the first transaction, or too far ahead
     */
    public function build(User $owner, ?\DateTimeImmutable $month = null, CalendarFilter $filter = CalendarFilter::Recurring): ?MonthCalendar
    {
        $range = $this->transactions->findDateRange($owner);
        if (null === $range) {
            return null;
        }

        $today = $this->clock->now()->setTime(0, 0);
        $current = Month::firstDayOf($today);
        $first = Month::firstDayOf($range[0]);
        $last = $current->modify(\sprintf('+%d months', self::MONTHS_AHEAD));
        $month = Month::firstDayOf($month ?? $current);
        if ($month->format('Y-m') < $first->format('Y-m') || $month->format('Y-m') > $last->format('Y-m')) {
            throw new \OutOfRangeException(\sprintf('No calendar before %s or after %s.', $first->format('Y-m'), $last->format('Y-m')));
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
            $knownUntil = $lastDates[$series->getAccount()->getId()] ?? $series->getLastSeenOn();
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

        $upcoming = $month >= $current;

        return new MonthCalendar(
            $month,
            $filter,
            $today,
            self::weeksOf($month),
            $entries,
            array_values($totals),
            $month > $first ? $month->modify('-1 month') : null,
            $month < $last ? $month->modify('+1 month') : null,
            $upcoming ? $toCheck : [],
            $upcoming ? $ended : [],
        );
    }

    /**
     * @return list<list<\DateTimeImmutable>>
     */
    private static function weeksOf(\DateTimeImmutable $month): array
    {
        $day = $month->modify(\sprintf('-%d days', (int) $month->format('N') - 1));
        $end = $month->modify('last day of this month');

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
