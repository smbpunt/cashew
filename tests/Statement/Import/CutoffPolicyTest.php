<?php

namespace App\Tests\Statement\Import;

use App\Statement\Import\CutoffPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class CutoffPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cutoffs(): iterable
    {
        yield 'mid-month' => ['2026-10-11 15:30', '2026-09-30'];
        yield 'first day of the month' => ['2026-11-01 00:00', '2026-10-31'];
        // The month isn't over until its last day is
        yield 'last day of the month' => ['2026-10-31 23:59', '2026-09-30'];
        yield 'January' => ['2026-01-15', '2025-12-31'];
        yield 'March, after a short February' => ['2026-03-31', '2026-02-28'];
    }

    #[DataProvider('cutoffs')]
    public function testTheCutoffIsTheEndOfTheLastWholeMonth(string $now, string $cutoff): void
    {
        $policy = new CutoffPolicy(new MockClock($now));

        self::assertSame($cutoff, $policy->cutoffDate()->format('Y-m-d'));
        self::assertTrue($policy->allows(new \DateTimeImmutable($cutoff)));
        self::assertFalse($policy->allows(new \DateTimeImmutable($cutoff)->modify('+1 day')));
    }
}
