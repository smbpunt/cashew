<?php

namespace App\Recurrence;

use App\Entity\StatementImport;
use App\Repository\TransactionRepository;

/**
 * Finds what imports brought to confirmed series at another amount than the time before.
 */
final readonly class AmountChangeFinder
{
    /**
     * After that many occurrences of the exact same amount, it's a fixed price.
     */
    private const int FIXED_PRICE_COUNT = 3;

    public function __construct(
        private TransactionRepository $transactions,
    ) {
    }

    /**
     * @param list<StatementImport> $imports
     *
     * @return list<AmountChange> oldest first
     */
    public function find(array $imports): array
    {
        $changes = [];
        $imported = array_map(static fn (StatementImport $import): ?int => $import->getId(), $imports);

        /** @var array<int, array<string, list<int>>> $amounts by series id, then by day */
        $amounts = [];
        foreach ($this->transactions->findOfConfirmedSeriesImportedBy($imports) as $transaction) {
            $series = $transaction->getRecurringSeries();
            \assert(null !== $series);
            $day = $transaction->getDate()->format('Y-m-d');

            $previous = array_filter($amounts[(int) $series->getId()] ?? [], static fn (string $other): bool => $other < $day, \ARRAY_FILTER_USE_KEY);
            $previousDay = array_key_last($previous);
            $amounts[(int) $series->getId()][$day][] = $transaction->getAmount();

            if (null === $previousDay || !\in_array($transaction->getStatementImport()?->getId(), $imported, true)) {
                continue;
            }

            // The same price for a while: any other is news, however small the change
            $lastAmounts = array_unique(array_merge(...array_values(\array_slice($previous, -self::FIXED_PRICE_COUNT))));
            if (\count($previous) >= self::FIXED_PRICE_COUNT && 1 === \count($lastAmounts)) {
                $changed = $transaction->getAmount() !== array_first($lastAmounts);
            } else {
                // Two lines of the same day: either may be the one it's the next of
                $changed = !array_any($previous[$previousDay], static fn (int $amount): bool => AmountTolerance::isSimilar($transaction->getAmount(), $amount, $transaction->getCurrency()));
            }

            if ($changed) {
                $changes[] = new AmountChange($transaction, $previous[$previousDay][0], new \DateTimeImmutable($previousDay));
            }
        }

        return $changes;
    }
}
