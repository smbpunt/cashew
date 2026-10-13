<?php

namespace App\Tests\Recurrence;

use App\Entity\BankAccount;
use App\Entity\RecurringSeries;
use App\Entity\User;
use App\Enum\Bank;
use App\Enum\Periodicity;
use App\Recurrence\SeriesSchedule;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class SeriesScheduleTest extends TestCase
{
    private SeriesSchedule $schedule;

    protected function setUp(): void
    {
        $this->schedule = new SeriesSchedule();
    }

    public function testExpectsTheOccurrencesOfTheMonthAfterTheLastDayKnown(): void
    {
        $series = $this->series(Periodicity::Monthly, '2026-09-05');

        self::assertSame(['2026-10-05'], $this->occurrencesIn($series, '2026-10', '2026-09-30'));
        self::assertSame(['2027-03-05'], $this->occurrencesIn($series, '2027-03', '2026-09-30'));
        // Known already
        self::assertSame([], $this->occurrencesIn($series, '2026-09', '2026-09-30'));
    }

    public function testExpectsEachOccurrenceOfAWeeklySeries(): void
    {
        $series = $this->series(Periodicity::Weekly, '2026-09-28');

        self::assertSame(['2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26'], $this->occurrencesIn($series, '2026-10', '2026-09-30'));
    }

    public function testExpectsNothingInAMonthASeriesSkips(): void
    {
        $series = $this->series(Periodicity::Quarterly, '2026-09-15');

        self::assertSame([], $this->occurrencesIn($series, '2026-10', '2026-09-30'));
        self::assertSame(['2026-12-15'], $this->occurrencesIn($series, '2026-12', '2026-09-30'));
    }

    #[TestWith(['2026-09-05', false])]
    // 35 days later
    #[TestWith(['2026-08-26', false])]
    #[TestWith(['2026-08-25', true])]
    #[TestWith(['2026-06-05', true])]
    public function testSeemsEndedOnceAnOccurrenceWentMissing(string $lastSeenOn, bool $seemsEnded): void
    {
        $series = $this->series(Periodicity::Monthly, $lastSeenOn);

        self::assertSame($seemsEnded, $this->schedule->seemsEnded($series, new \DateTimeImmutable('2026-09-30')));
        self::assertSame($seemsEnded, [] === $this->occurrencesIn($series, '2026-10', '2026-09-30'));
    }

    public function testAYearlySeriesDoesNotSeemEndedWithinTheYear(): void
    {
        $series = $this->series(Periodicity::Yearly, '2025-11-02');

        self::assertFalse($this->schedule->seemsEnded($series, new \DateTimeImmutable('2026-09-30')));
        self::assertSame(['2026-11-02'], $this->occurrencesIn($series, '2026-11', '2026-09-30'));
    }

    public function testStillActiveUntilAnotherOccurrenceGoesMissing(): void
    {
        $series = $this->series(Periodicity::Monthly, '2026-07-05');
        $series->keepActive(new \DateTimeImmutable('2026-09-30'));

        self::assertFalse($this->schedule->seemsEnded($series, new \DateTimeImmutable('2026-09-30')));
        self::assertSame(['2026-10-05'], $this->occurrencesIn($series, '2026-10', '2026-09-30'));
        // Still nothing a month later
        self::assertTrue($this->schedule->seemsEnded($series, new \DateTimeImmutable('2026-11-30')));
    }

    public function testAnEndedSeriesIsNoLongerExpected(): void
    {
        $series = $this->series(Periodicity::Monthly, '2026-09-05');
        $series->end(new \DateTimeImmutable('2026-10-11'));

        self::assertFalse($this->schedule->seemsEnded($series, new \DateTimeImmutable('2026-09-30')));
        self::assertSame([], $this->occurrencesIn($series, '2026-10', '2026-09-30'));

        $series->resume();
        self::assertSame(['2026-10-05'], $this->occurrencesIn($series, '2026-10', '2026-09-30'));
    }

    /**
     * @return list<string>
     */
    private function occurrencesIn(RecurringSeries $series, string $month, string $knownUntil): array
    {
        return array_map(
            static fn (\DateTimeImmutable $day): string => $day->format('Y-m-d'),
            $this->schedule->occurrencesIn($series, new \DateTimeImmutable($month.'-01'), new \DateTimeImmutable($knownUntil)),
        );
    }

    private function series(Periodicity $periodicity, string $lastSeenOn): RecurringSeries
    {
        $account = new BankAccount(new User(), Bank::CreditAgricole, '123', 'Compte de Dépôt', 'EUR');

        return new RecurringSeries($account, 'netflix', false, 'Netflix', $periodicity, -1349, 90, new \DateTimeImmutable($lastSeenOn));
    }
}
