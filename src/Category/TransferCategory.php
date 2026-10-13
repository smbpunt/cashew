<?php

namespace App\Category;

use App\Entity\Category;
use App\Entity\User;
use App\Enum\CategoryRole;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The user's "Transfers" category, which the import files transfers in: created in the language of the request the
 * first time it's needed, so on the user's login or upload rather than in the worker, which has none.
 */
final readonly class TransferCategory
{
    private const string KEY = 'transfers';
    private const string ICON = 'lucide:arrow-left-right';

    public function __construct(
        private CategoryRepository $categories,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Persists it if it's new, without flushing it. A top category of the user by that name already becomes it.
     */
    public function of(User $owner): Category
    {
        if (null !== $category = $this->categories->findByRole($owner, CategoryRole::Transfers) ?? $this->pending($owner)) {
            return $category;
        }

        $name = $this->translator->trans('category.defaults.'.self::KEY);
        $category = $this->categories->findOneBy(['owner' => $owner, 'parent' => null, 'name' => $name])
            ?? new Category($owner)->setName($name)->setIcon(self::ICON)->setDefaultKey(self::KEY);
        $this->entityManager->persist($category->setRole(CategoryRole::Transfers));

        return $category;
    }

    /**
     * The one persisted already, not flushed yet: asked for twice before a flush, it's created once.
     */
    private function pending(User $owner): ?Category
    {
        return array_find(
            $this->entityManager->getUnitOfWork()->getScheduledEntityInsertions(),
            static fn (object $entity): bool => $entity instanceof Category && $entity->getOwner() === $owner && CategoryRole::Transfers === $entity->getRole(),
        );
    }
}
