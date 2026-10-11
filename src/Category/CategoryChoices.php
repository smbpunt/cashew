<?php

namespace App\Category;

use App\Entity\Category;
use App\Entity\User;
use App\Repository\CategoryRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The user's categories, for the lists and selects of a page: queried once per request, however many there are.
 */
final class CategoryChoices implements ResetInterface
{
    /**
     * @var array<int, list<Category>> by user id
     */
    private array $trees = [];

    /**
     * @var array<int, list<Category>> by user id
     */
    private array $choices = [];

    public function __construct(
        private readonly CategoryRepository $categories,
    ) {
    }

    /**
     * The top categories by name, their subcategories loaded with them.
     *
     * @return list<Category>
     */
    public function tree(User $owner): array
    {
        return $this->trees[(int) $owner->getId()] ??= $this->categories->findTree($owner);
    }

    /**
     * As a select lists them: each top category followed by its subcategories.
     *
     * @return list<Category>
     */
    public function of(User $owner): array
    {
        return $this->choices[(int) $owner->getId()] ??= array_merge(...array_map(
            static fn (Category $category): array => [$category, ...$category->getChildren()],
            $this->tree($owner),
        ));
    }

    /**
     * The user's category of that id, if any.
     */
    public function find(User $owner, ?int $id): ?Category
    {
        foreach ($this->of($owner) as $category) {
            if ($category->getId() === $id) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Forgets the categories, for the next request to query them again.
     */
    public function reset(): void
    {
        $this->trees = $this->choices = [];
    }
}
