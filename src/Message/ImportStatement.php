<?php

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage('async')]
final readonly class ImportStatement
{
    public function __construct(
        public int $statementImportId,
    ) {
    }
}
