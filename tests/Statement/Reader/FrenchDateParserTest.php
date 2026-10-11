<?php

namespace App\Tests\Statement\Reader;

use App\Statement\Exception\UnreadableStatementException;
use App\Statement\Reader\FrenchDateParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrenchDateParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dates(): iterable
    {
        yield 'numeric' => ['08/10/2026', '2026-10-08'];
        yield 'full month' => ['30 septembre 2026', '2026-09-30'];
        yield 'single-digit day' => ['9 juin 2025', '2025-06-09'];
        yield 'abbreviated month' => ['12 juil. 2025', '2025-07-12'];
        yield 'abbreviated month with an accent' => ['3 févr. 2026', '2026-02-03'];
        yield 'month with an accent' => ['14 août 2026', '2026-08-14'];
        yield 'December' => ['1 déc. 2025', '2025-12-01'];
        yield 'capitalized month' => ['1 Janvier 2026', '2026-01-01'];
        yield 'first of the month' => ['1er mai 2026', '2026-05-01'];
    }

    #[DataProvider('dates')]
    public function testParse(string $value, string $expected): void
    {
        $date = new FrenchDateParser()->parse($value);

        self::assertSame($expected.' 00:00:00', $date->format('Y-m-d H:i:s'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notDates(): iterable
    {
        yield 'empty' => [''];
        yield 'impossible day' => ['31/09/2026'];
        yield 'unknown month' => ['3 brumaire 2026'];
        yield 'text' => ['Total'];
    }

    #[DataProvider('notDates')]
    public function testRefusesWhatIsNotADate(string $value): void
    {
        $this->expectException(UnreadableStatementException::class);

        new FrenchDateParser()->parse($value);
    }
}
