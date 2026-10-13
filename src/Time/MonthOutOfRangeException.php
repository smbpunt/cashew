<?php

namespace App\Time;

use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * A month asked for out of those a page shows: before the first transaction, or after the last.
 */
#[WithHttpStatus(404)]
final class MonthOutOfRangeException extends \OutOfRangeException
{
    public static function outside(Month $month, Month $first, Month $last): self
    {
        return new self(\sprintf('No %s: only %s to %s.', $month, $first, $last));
    }
}
