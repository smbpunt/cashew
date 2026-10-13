<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\User;
use App\Enum\CategoryRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * The owner's top categories by name, their subcategories loaded with them.
     *
     * @return list<Category>
     */
    public function findTree(User $owner): array
    {
        return Category::sortByName($this->createQueryBuilder('c')
            ->addSelect('child')
            ->leftJoin('c.children', 'child')
            ->where('c.owner = :owner')
            ->andWhere('c.parent IS NULL')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getResult());
    }

    /**
     * Whether the owner has categories of their own, the system ones aside: "Transfers" alone is no reason not to
     * offer them the default ones.
     */
    public function hasAny(User $owner): bool
    {
        return 0 < $this->count(['owner' => $owner, 'role' => null]);
    }

    public function findByRole(User $owner, CategoryRole $role): ?Category
    {
        return $this->findOneBy(['owner' => $owner, 'role' => $role]);
    }
}
