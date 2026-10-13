<?php

namespace App\Recurrence;

use App\Entity\Transaction;
use App\Enum\Periodicity;

/**
 * Tells whether transactions of the same counterparty come back regularly, and how sure it is of it.
 *
 * Over the last twelve occurrences, the confidence multiplies how regular the days between them are, how stable the
 * amount is from one to the next, and how many there are, up to six: each one counts, and a regular day doesn't make
 * up for random amounts. A subscription of a fixed amount, three months in a row, scores 85; the shopping of every
 * Saturday, never the same amount, stays under 60, and so does the shopping of any day.
 */
final readonly class SeriesAnalyzer
{
    /**
     * The occurrences the confidence looks at: the last ones, for an old irregularity not to weigh forever.
     */
    private const int RECENT_COUNT = 12;

    /**
     * The occurrences the typical amount is the median of: the last ones, for it to follow a new price.
     */
    private const int LATEST_COUNT = 3;

    private const int FULL_COUNT = 6;

    /**
     * @param list<Transaction> $transactions of the same account and counterparty, in any order
     *
     * @return SeriesAnalysis|null null when they don't come back at any known periodicity
     */
    public function analyze(array $transactions): ?SeriesAnalysis
    {
        // Two lines of the same day (two phone lines) are one occurrence
        $amountsByDay = [];
        foreach ($transactions as $transaction) {
            $amountsByDay[$transaction->getDate()->format('Y-m-d')][] = $transaction->getAmount();
        }
        ksort($amountsByDay);
        $count = \count($amountsByDay);

        $recent = \array_slice($amountsByDay, -self::RECENT_COUNT, preserve_keys: true);
        $days = array_keys($recent);
        $intervals = [];
        for ($i = 1; $i < \count($days); ++$i) {
            $intervals[] = (int) new \DateTimeImmutable($days[$i - 1])->diff(new \DateTimeImmutable($days[$i]))->days;
        }

        [$periodicity, $regularity] = self::periodicityOf($intervals) ?? [null, 0.0];
        if (null === $periodicity || $count < $periodicity->minOccurrences()) {
            return null;
        }

        // From one occurrence to the next: a price rise once doesn't make it unstable
        $currency = array_first($transactions)->getCurrency();
        $stable = 0;
        for ($i = 1; $i < \count($days); ++$i) {
            $stable += (int) self::haveSimilarAmounts($recent[$days[$i]], $recent[$days[$i - 1]], $currency);
        }
        $stability = $stable / \count($intervals);
        // Twice only: the same amount, or it may well be a coincidence
        if (2 === $count && $stability < 1) {
            return null;
        }

        $occurrences = min(1, $count / self::FULL_COUNT);

        return new SeriesAnalysis(
            $periodicity,
            self::median(array_merge(...array_values(\array_slice($amountsByDay, -self::LATEST_COUNT)))),
            (int) round(100 * $regularity * (0.3 + 0.7 * $stability) * (0.7 + 0.3 * $occurrences)),
            new \DateTimeImmutable((string) array_key_last($amountsByDay)),
        );
    }

    /**
     * The periodicity most of the intervals are on time for, and the share of them that are; one or two occurrences
     * may have been missed, but most must follow the one before by a single period, or a monthly series would be
     * weekly too, every four weeks.
     *
     * @param list<int> $intervals in days
     *
     * @return array{Periodicity, float}|null
     */
    private static function periodicityOf(array $intervals): ?array
    {
        if ([] === $intervals) {
            return null;
        }

        $best = null;
        foreach (Periodicity::cases() as $periodicity) {
            $periods = array_map($periodicity->periodsIn(...), $intervals);
            $onTime = \count(array_filter($periods, static fn (?int $periods): bool => null !== $periods));
            $single = \count(array_filter($periods, static fn (?int $periods): bool => 1 === $periods));
            if (0 === $onTime || 2 * $single < $onTime) {
                continue;
            }

            // On a tie, the one with the most single periods; then the shortest, the first of the cases
            $score = [$onTime, $single];
            if (null === $best || $score > $best[2]) {
                $best = [$periodicity, $onTime / \count($intervals), $score];
            }
        }

        return null === $best ? null : [$best[0], $best[1]];
    }

    /**
     * Whether one of the amounts of a day is the same price as one of another's.
     *
     * @param list<int> $amounts
     * @param list<int> $others
     */
    private static function haveSimilarAmounts(array $amounts, array $others, string $currency): bool
    {
        foreach ($amounts as $amount) {
            foreach ($others as $other) {
                if (AmountTolerance::isSimilar($amount, $other, $currency)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param non-empty-list<int> $values
     */
    private static function median(array $values): int
    {
        sort($values);
        $middle = intdiv(\count($values), 2);

        return 0 === \count($values) % 2 ? intdiv($values[$middle - 1] + $values[$middle], 2) : $values[$middle];
    }
}
