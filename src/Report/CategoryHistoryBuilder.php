<?php

namespace App\Report;

use App\Entity\Category;
use App\Entity\Transaction;
use App\Repository\TransactionRepository;
use Psr\Clock\ClockInterface;

/**
 * Sums up a category month by month: over the last twelve months with transactions, or fewer when there are fewer.
 */
final readonly class CategoryHistoryBuilder
{
    private const int MONTHS = 12;

    public function __construct(
        private TransactionRepository $transactions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param \DateTimeImmutable|null $month any day of the month to list the transactions of; null, or out of the
     *                                       period, for the last one
     */
    public function build(Category $category, ?\DateTimeImmutable $month = null): CategoryHistory
    {
        $owner = $category->getOwner();
        $range = $this->transactions->findDateRange($owner);
        if (null === $range) {
            return new CategoryHistory($category, [], [], Month::firstDayOf($month ?? $this->clock->now()), []);
        }

        [$first, $last] = array_map(Month::firstDayOf(...), $range);
        $start = max($first, $last->modify(\sprintf('-%d months', self::MONTHS - 1)));

        $months = [];
        for ($current = $start; $current <= $last; $current = $current->modify('+1 month')) {
            $months[] = $current;
        }

        $month = null === $month ? $last : Month::firstDayOf($month);
        if ($month < $start || $month > $last) {
            $month = $last;
        }

        $transactions = $this->transactions->findInCategories($owner, [$category, ...$category->getChildren()], $start, $last->modify('+1 month'));

        $amounts = [];
        $empty = array_fill_keys(array_map(static fn (\DateTimeImmutable $month): string => $month->format('Y-m'), $months), 0);
        foreach ($transactions as $transaction) {
            $amounts[$transaction->getCurrency()] ??= $empty;
            $amounts[$transaction->getCurrency()][$transaction->getDate()->format('Y-m')] += $transaction->getAmount();
        }
        ksort($amounts);

        return new CategoryHistory(
            $category,
            $months,
            $amounts,
            $month,
            array_values(array_filter($transactions, static fn (Transaction $transaction): bool => $transaction->getDate()->format('Y-m') === $month->format('Y-m'))),
        );
    }
}
