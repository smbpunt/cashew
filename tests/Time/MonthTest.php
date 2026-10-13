<?php

namespace App\Tests\Time;

use App\Time\Month;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class MonthTest extends TestCase
{
    public function testOfADay(): void
    {
        $month = Month::of(new \DateTimeImmutable('2026-09-17 15:00'));

        self::assertSame('2026-09', $month->key());
        self::assertSame('2026-09-01 00:00', $month->firstDay()->format('Y-m-d H:i'));
        self::assertSame('2026-09-30 00:00', $month->lastDay()->format('Y-m-d H:i'));
        self::assertTrue($month->contains(new \DateTimeImmutable('2026-09-30 23:59')));
        self::assertFalse($month->contains(new \DateTimeImmutable('2026-10-01')));
    }

    #[TestWith(['2026-9'])]
    #[TestWith(['2026-13'])]
    #[TestWith(['2026-00'])]
    #[TestWith(['september'])]
    public function testFromWhatIsNotAKey(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Month::fromKey($key);
    }

    public function testAcrossYears(): void
    {
        $december = Month::fromKey('2026-12');

        self::assertSame('2027-01', $december->next()->key());
        self::assertSame('2025-12', $december->plus(-12)->key());
        self::assertSame('2026-01', $december->plus(-11)->key());
        self::assertSame(13, Month::fromKey('2025-12')->monthsUntil(Month::fromKey('2027-01')));
        self::assertSame(-1, $december->monthsUntil($december->previous()));
    }

    public function testComparison(): void
    {
        $september = Month::fromKey('2026-09');
        $october = $september->next();

        self::assertTrue($september->isBefore($october));
        self::assertTrue($october->isAfter($september));
        self::assertTrue($september->equals(Month::fromKey('2026-09')));
        self::assertSame($september, $september->earliest($october));
        self::assertSame($october, $september->latest($october));
    }

    public function testRange(): void
    {
        self::assertSame(['2026-11', '2026-12', '2027-01'], array_map(strval(...), Month::range(Month::fromKey('2026-11'), Month::fromKey('2027-01'))));
        self::assertSame([], Month::range(Month::fromKey('2027-01'), Month::fromKey('2026-11')));
    }
}
