<?php

namespace App\Report;

use App\Entity\Category;
use App\Entity\Transaction;

/**
 * A category month by month, its subcategories included.
 */
final readonly class CategoryHistory
{
    public function __construct(
        public Category $category,
        /**
         * The first day of each month, oldest first: the last twelve with transactions.
         *
         * @var list<\DateTimeImmutable>
         */
        public array $months,
        /**
         * In minor units (cents), negative when it's spent on: by currency, then by month ("Y-m"), every month there.
         *
         * @var array<string, array<string, int>>
         */
        public array $amounts,
        /**
         * The month whose transactions are listed.
         */
        public \DateTimeImmutable $month,
        /**
         * Most recent first.
         *
         * @var list<Transaction>
         */
        public array $transactions,
    ) {
    }

    /**
     * What it comes to in a month, on average over the months since the first transaction.
     */
    public function getAverage(string $currency): int
    {
        return [] === $this->months ? 0 : (int) round(array_sum($this->amounts[$currency]) / \count($this->months));
    }

    /**
     * The largest amount of a month, for the bars to be drawn to scale.
     */
    public function getMaxAmount(string $currency): int
    {
        return max(1, ...array_map(abs(...), array_values($this->amounts[$currency])));
    }
}
