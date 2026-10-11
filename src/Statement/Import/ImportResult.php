<?php

namespace App\Statement\Import;

use App\Enum\Bank;

final readonly class ImportResult
{
    public function __construct(
        public Bank $bank,
        public int $importedCount,
        public int $skippedCount,
        public int $afterCutoffCount,
    ) {
    }
}
