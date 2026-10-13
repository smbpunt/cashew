<?php

namespace App\Statement\Reader;

use App\Statement\Exception\UnreadableStatementException;
use Symfony\Component\Intl\Currencies;

/**
 * Reads amounts as banks write them: "-1 234,56 €", "1,234.56", "12,34AU$"...
 */
final class MoneyParser
{
    private const array CURRENCY_SYMBOLS = [
        '€' => 'EUR',
        '$' => 'USD',
        'US$' => 'USD',
        'AU$' => 'AUD',
        'CA$' => 'CAD',
        '£' => 'GBP',
        '¥' => 'JPY',
    ];

    private const string PATTERN = '/^
        (?<sign>[-+\x{2212}]?)\s*
        (?<before>[^\d\s,.+\x{2212}-]*)\s*
        (?<sign2>[-+\x{2212}]?)
        (?<number>\d[\d\s,.]*?)\s*
        (?<after>[^\d\s,.]*)
    $/ux';

    /**
     * @param string $currency the one of the statement, for the amounts that don't spell theirs out: it tells how many
     *                         minor units make a major one (100 cents, but 1 yen)
     */
    public function parse(string $value, string $currency): Money
    {
        // \s doesn't cover the no-break spaces that group digits in French
        $normalized = trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $value));

        if (!preg_match(self::PATTERN, $normalized, $matches) || ('' !== $matches['before'] && '' !== $matches['after'])) {
            throw new UnreadableStatementException('statement.error.not_an_amount', ['value' => $value]);
        }

        $negative = \in_array($matches['sign'], ['-', "\u{2212}"], true) || \in_array($matches['sign2'], ['-', "\u{2212}"], true);
        $symbol = $matches['before'].$matches['after'];
        $spelled = '' === $symbol ? null : $this->currency($symbol, $value);
        $amount = $this->toMinorUnits($matches['number'], Currencies::getFractionDigits($spelled ?? $currency), $value);

        return new Money(($negative ? -1 : 1) * $amount, $spelled);
    }

    private function toMinorUnits(string $number, int $fractionDigits, string $value): int
    {
        $number = preg_replace('/\s+/', ' ', $number);

        // The decimal separator is the last "," or "." when 1 or 2 digits follow it; any other one groups thousands
        if (preg_match('/^(?<integer>.*?)[,.](?<decimals>\d{1,2})$/', $number, $matches)) {
            $integer = $matches['integer'];
            $decimals = $matches['decimals'];
        } else {
            $integer = $number;
            $decimals = '';
        }

        // "1 234,00 ¥" has no yen cents, but "1 234,50 ¥" isn't an amount in yens
        if (\strlen(rtrim($decimals, '0')) > $fractionDigits) {
            throw new UnreadableStatementException('statement.error.not_an_amount', ['value' => $value]);
        }
        $decimals = str_pad(substr($decimals, 0, $fractionDigits), $fractionDigits, '0');

        if (!preg_match('/^\d{1,3}(?:[ ,.]?\d{3})*$/', $integer)) {
            throw new UnreadableStatementException('statement.error.not_an_amount', ['value' => $value]);
        }

        return (int) (str_replace([' ', ',', '.'], '', $integer).$decimals);
    }

    private function currency(string $symbol, string $value): string
    {
        if (preg_match('/^[A-Z]{3}$/', $symbol)) {
            return $symbol;
        }

        return self::CURRENCY_SYMBOLS[$symbol] ?? throw new UnreadableStatementException('statement.error.unknown_currency', ['value' => $value]);
    }
}
