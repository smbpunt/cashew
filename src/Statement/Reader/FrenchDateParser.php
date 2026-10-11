<?php

namespace App\Statement\Reader;

use App\Statement\Exception\UnreadableStatementException;

/**
 * Reads dates as French banks write them: "09/10/2026", "9 octobre 2026", "9 oct. 2026".
 */
final class FrenchDateParser
{
    private const array MONTHS = [
        'janvier' => 1, 'janv' => 1,
        'février' => 2, 'févr' => 2, 'fevrier' => 2, 'fevr' => 2,
        'mars' => 3,
        'avril' => 4, 'avr' => 4,
        'mai' => 5,
        'juin' => 6,
        'juillet' => 7, 'juil' => 7,
        'août' => 8, 'aout' => 8,
        'septembre' => 9, 'sept' => 9,
        'octobre' => 10, 'oct' => 10,
        'novembre' => 11, 'nov' => 11,
        'décembre' => 12, 'déc' => 12, 'decembre' => 12, 'dec' => 12,
    ];

    /**
     * @return \DateTimeImmutable at midnight, in the default time zone
     */
    public function parse(string $value): \DateTimeImmutable
    {
        $value = trim($value);

        if (preg_match('#^(?<day>\d{1,2})/(?<month>\d{1,2})/(?<year>\d{4})$#', $value, $matches)) {
            return $this->date((int) $matches['year'], (int) $matches['month'], (int) $matches['day'], $value);
        }

        if (preg_match('/^(?<day>\d{1,2})(?:er)?\s+(?<month>\p{L}+)\.?\s+(?<year>\d{4})$/u', $value, $matches)) {
            $month = self::MONTHS[mb_strtolower($matches['month'])] ?? throw new UnreadableStatementException(\sprintf('"%s" isn\'t a date.', $value));

            return $this->date((int) $matches['year'], $month, (int) $matches['day'], $value);
        }

        throw new UnreadableStatementException(\sprintf('"%s" isn\'t a date.', $value));
    }

    private function date(int $year, int $month, int $day, string $value): \DateTimeImmutable
    {
        if (!checkdate($month, $day, $year)) {
            throw new UnreadableStatementException(\sprintf('"%s" isn\'t a date.', $value));
        }

        return new \DateTimeImmutable()->setDate($year, $month, $day)->setTime(0, 0);
    }
}
