<?php

namespace App\Twig;

use Symfony\Component\Intl\Currencies;
use Twig\Attribute\AsTwigFilter;
use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;

final class MoneyExtension
{
    /**
     * An amount in minor units (cents), formatted in the language of the page: "-12,34 €".
     */
    #[AsTwigFilter('money', needsEnvironment: true)]
    public function money(Environment $twig, int $amount, string $currency): string
    {
        return $twig->getExtension(IntlExtension::class)->formatCurrency($amount / 10 ** Currencies::getFractionDigits($currency), $currency);
    }
}
