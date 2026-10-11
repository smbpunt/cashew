<?php

namespace App\Statement\Reader;

use App\Statement\Exception\UnreadableStatementException;

final class CsvReader
{
    /**
     * Streams the records of a CSV file, decoded to UTF-8. Blank lines come as [''].
     *
     * @return \Generator<int, list<string>>
     */
    public function read(\SplFileInfo $file, string $separator, string $encoding = 'UTF-8'): \Generator
    {
        $handle = @fopen($file->getPathname(), 'r');
        if (false === $handle) {
            throw new UnreadableStatementException('statement.error.cannot_open');
        }

        try {
            if ('UTF-8' !== $encoding) {
                stream_filter_append($handle, \sprintf('convert.iconv.%s/UTF-8', $encoding), \STREAM_FILTER_READ);
            } elseif ("\u{FEFF}" !== fread($handle, 3)) {
                // Skipped before parsing, or a quoted first cell would keep its quotes
                rewind($handle);
            }

            while (false !== $record = fgetcsv($handle, null, $separator, '"', '')) {
                yield array_map(static fn (?string $cell): string => $cell ?? '', $record);
            }
        } finally {
            fclose($handle);
        }
    }
}
