<?php

namespace App\Enum;

/**
 * The page a first set of categories is asked for from, to come back to.
 */
enum SeedOrigin: string
{
    case Categories = 'categories';
    case Review = 'review';

    public function route(): string
    {
        return match ($this) {
            self::Categories => 'app_categories',
            self::Review => 'app_review',
        };
    }
}
