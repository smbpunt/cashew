<?php

namespace App\Twig\Components;

use App\Category\CategoryChoices;
use App\Entity\Category;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The user's categories, each top one with its subcategories, up to date with those created on the page.
 */
#[AsLiveComponent]
#[IsGranted('ROLE_USER')]
final class CategoryTree
{
    use DefaultActionTrait;

    public function __construct(
        private readonly CategoryChoices $categories,
        private readonly Security $security,
    ) {
    }

    #[LiveListener('category:created')]
    public function onCategoryCreated(): void
    {
    }

    /**
     * @return list<Category>
     */
    public function getCategories(): array
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        return $this->categories->tree($user);
    }
}
