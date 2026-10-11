<?php

namespace App\Report;

use App\Entity\Category;
use App\Entity\Transaction;

/**
 * Where the money of a month went, in one currency: by top category, then by subcategory.
 */
final readonly class CategoryBreakdown
{
    public function __construct(
        public string $currency,
        /**
         * The most spent on first.
         *
         * @var list<CategoryTotal>
         */
        public array $categories,
        /**
         * The debits without a category, negative.
         */
        public int $uncategorizedSpending,
        /**
         * The debits of the series the user confirmed (subscriptions, bills, loans...), negative.
         */
        public int $fixedCharges,
    ) {
    }

    /**
     * @param iterable<Transaction> $transactions those in other currencies are left out
     */
    public static function of(string $currency, iterable $transactions): self
    {
        /** @var array<int, Category> $roots */
        $roots = [];
        /** @var array<int, int> $amounts by category, subcategories included */
        $amounts = [];
        /** @var array<int, array<int, Category>> $children by top category */
        $children = [];
        $uncategorized = $fixed = 0;

        foreach ($transactions as $transaction) {
            if ($transaction->getCurrency() !== $currency) {
                continue;
            }

            $amount = $transaction->getAmount();
            if ($transaction->isRecurring() && $amount < 0) {
                $fixed += $amount;
            }

            $category = $transaction->getEffectiveCategory();
            if (null === $category) {
                $uncategorized += min(0, $amount);
                continue;
            }

            $root = $category->getRoot();
            $roots[spl_object_id($root)] = $root;
            $amounts[spl_object_id($root)] = ($amounts[spl_object_id($root)] ?? 0) + $amount;
            if ($category !== $root) {
                $children[spl_object_id($root)][spl_object_id($category)] = $category;
                $amounts[spl_object_id($category)] = ($amounts[spl_object_id($category)] ?? 0) + $amount;
            }
        }

        $total = static fn (Category $category, array $children = []): CategoryTotal => new CategoryTotal($category, $amounts[spl_object_id($category)], $children);
        $totals = [];
        foreach ($roots as $id => $root) {
            $totals[] = $total($root, self::sorted(array_map($total, array_values($children[$id] ?? []))));
        }

        return new self($currency, self::sorted($totals), $uncategorized, $fixed);
    }

    /**
     * @param list<CategoryTotal> $totals
     *
     * @return list<CategoryTotal>
     */
    private static function sorted(array $totals): array
    {
        usort($totals, static fn (CategoryTotal $a, CategoryTotal $b): int => [$a->amount, $a->category->getName()] <=> [$b->amount, $b->category->getName()]);

        return $totals;
    }

    public function isEmpty(): bool
    {
        return [] === $this->categories && 0 === $this->uncategorizedSpending;
    }
}
