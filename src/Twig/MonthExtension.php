<?php

namespace App\Twig;

use App\Time\Month;
use Twig\Attribute\AsTwigFilter;

final class MonthExtension
{
    /**
     * The month as a title, in the language of the page: "Septembre 2026".
     */
    #[AsTwigFilter('month_name')]
    public function monthName(Month|\DateTimeInterface $month): string
    {
        $day = $month instanceof Month ? $month->firstDay() : $month;
        $name = (string) \IntlDateFormatter::create(\Locale::getDefault(), pattern: 'LLLL y')->format($day);

        return mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
    }
}
