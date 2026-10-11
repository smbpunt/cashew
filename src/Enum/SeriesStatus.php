<?php

namespace App\Enum;

/**
 * Where a recurring series stands with its owner.
 */
enum SeriesStatus: string
{
    /**
     * Detected, for the user to review.
     */
    case Suggested = 'suggested';

    /**
     * Recognised by the user: its new transactions join it on each import, and take its category.
     */
    case Confirmed = 'confirmed';

    /**
     * Not one, according to the user: kept for the detection not to suggest it again.
     */
    case Rejected = 'rejected';
}
