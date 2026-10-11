<?php

namespace App\Tests\Statement\Reader;

use App\Statement\Exception\UnreadableStatementException;
use App\Statement\Reader\MoneyParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, ?string}>
     */
    public static function amounts(): iterable
    {
        yield 'French decimals' => ['19,99', 1999, null];
        yield 'French thousands with a space' => ['2 500,00', 250000, null];
        yield 'French thousands with a narrow no-break space' => ["1\u{202F}500,00€", 150000, 'EUR'];
        yield 'French thousands with a no-break space' => ["1\u{00A0}500,00 €", 150000, 'EUR'];
        yield 'English thousands and decimals' => ['-9,999.99 €', -999999, 'EUR'];
        yield 'English decimals' => ['-62.40 €', -6240, 'EUR'];
        yield 'one decimal' => ['12,5', 1250, null];
        yield 'no decimals' => ['45', 4500, null];
        yield 'thousands without decimals' => ['1,234', 123400, null];
        yield 'currency symbol suffix' => ['-20,00€', -2000, 'EUR'];
        yield 'multi-character symbol' => ['50,00AU$', 5000, 'AUD'];
        yield 'currency symbol prefix' => ['£12.30', 1230, 'GBP'];
        yield 'sign before the symbol' => ['-€12.30', -1230, 'EUR'];
        yield 'ISO code' => ['12,30 CHF', 1230, 'CHF'];
        yield 'minus sign' => ["\u{2212}3,00", -300, null];
        yield 'plus sign' => ['+3,00', 300, null];
    }

    #[DataProvider('amounts')]
    public function testParse(string $value, int $amount, ?string $currency): void
    {
        $money = new MoneyParser()->parse($value);

        self::assertSame($amount, $money->amount);
        self::assertSame($currency, $money->currency);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAmounts(): iterable
    {
        yield 'empty' => [''];
        yield 'text' => ['N/A'];
        yield 'misplaced thousands separator' => ['12 34,00'];
        yield 'unknown currency' => ['12,00 ¤'];
        yield 'symbols on both sides' => ['€12,00€'];
    }

    #[DataProvider('notAmounts')]
    public function testRefusesWhatIsNotAnAmount(string $value): void
    {
        $this->expectException(UnreadableStatementException::class);

        new MoneyParser()->parse($value);
    }
}
