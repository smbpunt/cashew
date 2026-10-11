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
            throw new UnreadableStatementException('The statement file can\'t be opened.');
        }

        try {
            if ('UTF-8' !== $encoding) {
                stream_filter_append($handle, \sprintf('convert.iconv.%s/UTF-8', $encoding), \STREAM_FILTER_READ);
            }

            $isFirst = true;
            while (false !== $record = fgetcsv($handle, null, $separator, '"', '')) {
                $record = array_map(static fn (?string $cell): string => $cell ?? '', $record);

                if ($isFirst && str_starts_with($record[0], "\u{FEFF}")) {
                    $record[0] = substr($record[0], 3);
                }
                $isFirst = false;

                yield $record;
            }
        } finally {
            fclose($handle);
        }
    }
}
