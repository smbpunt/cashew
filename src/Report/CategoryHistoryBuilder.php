<?php

namespace App\Report;

use App\Entity\Category;
use App\Entity\Transaction;
use App\Repository\TransactionRepository;
use App\Time\Month;
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
     * @param Month|null $month the month to list the transactions of; null, or out of the period, for the last one
     */
    public function build(Category $category, ?Month $month = null): CategoryHistory
    {
        $owner = $category->getOwner();
        $range = $this->transactions->findDateRange($owner);
        if (null === $range) {
            return new CategoryHistory($category, [], [], $month ?? Month::of($this->clock->now()), []);
        }

        [$first, $last] = array_map(Month::of(...), $range);
        $start = $first->latest($last->plus(1 - self::MONTHS));
        $months = Month::range($start, $last);

        if (null === $month || $month->isBefore($start) || $month->isAfter($last)) {
            $month = $last;
        }

        $transactions = $this->transactions->findInCategories($owner, [$category, ...$category->getChildren()], $start->firstDay(), $last->next()->firstDay());

        $amounts = [];
        $empty = array_fill_keys(array_map(static fn (Month $month): string => $month->key(), $months), 0);
        foreach ($transactions as $transaction) {
            // Listed, but neither spent nor earned
            if (!$transaction->countsInTotals()) {
                continue;
            }
            $amounts[$transaction->getCurrency()] ??= $empty;
            $amounts[$transaction->getCurrency()][Month::of($transaction->getDate())->key()] += $transaction->getAmount();
        }
        ksort($amounts);

        return new CategoryHistory(
            $category,
            $months,
            $amounts,
            $month,
            array_values(array_filter($transactions, static fn (Transaction $transaction): bool => $month->contains($transaction->getDate()))),
        );
    }
}
