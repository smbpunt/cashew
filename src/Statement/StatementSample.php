<?php

namespace App\Statement;

use App\Statement\Exception\UnreadableStatementException;

/**
 * The beginning of a statement file, decoded to UTF-8: enough for a parser to recognize its format.
 */
final readonly class StatementSample
{
    private function __construct(
        public string $text,
    ) {
    }

    public static function fromFile(\SplFileInfo $file, int $length = 8192): self
    {
        $handle = @fopen($file->getPathname(), 'r');
        if (false === $handle) {
            throw new UnreadableStatementException('statement.error.cannot_open');
        }

        try {
            $head = (string) fread($handle, $length);
            $isComplete = feof($handle);
        } finally {
            fclose($handle);
        }

        // Don't let a multibyte character cut in half pass the file off as non UTF-8
        if (!$isComplete && false !== $lastNewline = strrpos($head, "\n")) {
            $head = substr($head, 0, $lastNewline);
        }

        return self::fromString($head);
    }

    public static function fromString(string $head): self
    {
        if (str_starts_with($head, "\u{FEFF}")) {
            $head = substr($head, 3);
        }

        // Statements that aren't UTF-8 are, in practice, in the Windows code page of Western Europe
        if (!mb_check_encoding($head, 'UTF-8')) {
            $head = mb_convert_encoding($head, 'UTF-8', 'Windows-1252');
        }

        return new self($head);
    }

    public function contains(string ...$needles): bool
    {
        foreach ($needles as $needle) {
            if (!str_contains($this->text, $needle)) {
                return false;
            }
        }

        return true;
    }
}
