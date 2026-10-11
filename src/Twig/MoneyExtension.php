<?php

namespace App\Twig;

use Symfony\Component\Intl\Currencies;
use Twig\Attribute\AsTwigFilter;

final class MoneyExtension
{
    /**
     * An amount in minor units (cents), as format_currency expects it: in major units.
     */
    #[AsTwigFilter('major_units')]
    public function majorUnits(int $amount, string $currency): float
    {
        return $amount / 10 ** Currencies::getFractionDigits($currency);
    }
}
