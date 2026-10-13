<?php

namespace App\Tests\Enum;

use App\Enum\Periodicity;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class PeriodicityTest extends TestCase
{
    #[TestWith([Periodicity::Weekly, '2026-09-28', 1, '2026-10-05'])]
    #[TestWith([Periodicity::Weekly, '2026-09-28', 3, '2026-10-19'])]
    #[TestWith([Periodicity::Monthly, '2026-09-05', 1, '2026-10-05'])]
    #[TestWith([Periodicity::Monthly, '2026-09-05', 4, '2027-01-05'])]
    #[TestWith([Periodicity::Bimonthly, '2026-09-15', 1, '2026-11-15'])]
    #[TestWith([Periodicity::Quarterly, '2026-09-15', 2, '2027-03-15'])]
    #[TestWith([Periodicity::Semiannual, '2026-04-10', 1, '2026-10-10'])]
    #[TestWith([Periodicity::Yearly, '2025-11-02', 1, '2026-11-02'])]
    public function testTellsTheDayOfTheNthOccurrence(Periodicity $periodicity, string $anchor, int $n, string $expected): void
    {
        self::assertSame($expected, $periodicity->nth(new \DateTimeImmutable($anchor), $n)->format('Y-m-d'));
    }

    #[TestWith(['2026-10-31', 1, '2026-11-30'])]
    #[TestWith(['2026-10-31', 2, '2026-12-31'])]
    #[TestWith(['2027-01-30', 1, '2027-02-28'])]
    #[TestWith(['2028-01-31', 1, '2028-02-29'])]
    public function testADayMissingFromAShorterMonthIsItsLast(string $anchor, int $n, string $expected): void
    {
        self::assertSame($expected, Periodicity::Monthly->nth(new \DateTimeImmutable($anchor), $n)->format('Y-m-d'));
    }

    public function testTheMostDaysAPeriodMayLastIsThoseOnTime(): void
    {
        self::assertSame(35, Periodicity::Monthly->maxDays());
        self::assertTrue(Periodicity::Monthly->allows(35));
        self::assertFalse(Periodicity::Monthly->allows(36));
        self::assertSame(380, Periodicity::Yearly->maxDays());
    }
}
