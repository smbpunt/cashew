<?php

namespace App\Tests\Recurrence;

use App\Recurrence\AmountTolerance;
use PHPUnit\Framework\TestCase;

final class AmountToleranceTest extends TestCase
{
    public function testWithinTenPercent(): void
    {
        self::assertTrue(AmountTolerance::isSimilar(-10900, -10000, 'EUR'));
        self::assertFalse(AmountTolerance::isSimilar(-11100, -10000, 'EUR'));
    }

    public function testSmallAmountsWithinOneUnitOfTheirCurrency(): void
    {
        self::assertTrue(AmountTolerance::isSimilar(-299, -199, 'EUR'));
        self::assertFalse(AmountTolerance::isSimilar(-300, -199, 'EUR'));
        // 1 yen, not 100
        self::assertTrue(AmountTolerance::isSimilar(-6, -5, 'JPY'));
        self::assertFalse(AmountTolerance::isSimilar(-7, -5, 'JPY'));
    }
}
