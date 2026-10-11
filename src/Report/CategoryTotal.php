<?php

namespace App\Report;

use App\Entity\Category;

/**
 * What a category's transactions add up to, in minor units (cents): negative when it's spent on.
 */
final readonly class CategoryTotal
{
    public function __construct(
        public Category $category,
        public int $amount,
        /**
         * Those of its subcategories, which its amount includes; the most spent on first.
         *
         * @var list<CategoryTotal>
         */
        public array $children = [],
    ) {
    }
}
