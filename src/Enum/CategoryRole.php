<?php

namespace App\Enum;

/**
 * What a category the application relies on is for: the user may rename it, not delete it.
 */
enum CategoryRole: string
{
    /**
     * The money sent to someone else, or from one of the user's accounts to another.
     */
    case Transfers = 'transfers';
}
