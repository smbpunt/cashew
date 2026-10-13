<?php

namespace App\Recurrence;

use Symfony\Component\Intl\Currencies;

/**
 * Whether an amount is close enough to another to be the same price: within 10 %, or 1 unit of their currency for
 * small amounts (1 euro, 1 yen).
 */
final class AmountTolerance
{
    private const float SHARE = 0.1;

    /**
     * @param int $amount    in minor units of the currency
     * @param int $reference in minor units of the currency
     */
    public static function isSimilar(int $amount, int $reference, string $currency): bool
    {
        $unit = 10 ** Currencies::getFractionDigits($currency);

        return abs($amount - $reference) <= max($unit, (int) round(abs($reference) * self::SHARE));
    }
}
