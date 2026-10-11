<?php

namespace App\Enum;

enum ImportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return self::Done === $this || self::Failed === $this;
    }
}
