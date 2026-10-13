<?php

namespace App\Repository;

use App\Entity\RecurringSeries;
use App\Entity\User;
use App\Enum\SeriesStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecurringSeries>
 */
class RecurringSeriesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecurringSeries::class);
    }

    /**
     * @return list<RecurringSeries>
     */
    public function findOwned(User $owner): array
    {
        return $this->owned($owner)->getQuery()->getResult();
    }

    /**
     * The owner's series waiting for a review, the surest first.
     *
     * @return list<RecurringSeries>
     */
    public function findSuggested(User $owner): array
    {
        return $this->owned($owner)
            ->andWhere('s.status = :suggested')
            ->setParameter('suggested', SeriesStatus::Suggested)
            ->orderBy('s.confidence', 'DESC')
            ->addOrderBy('sortName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The owner's spending the user confirmed is recurring, with its category, by name.
     *
     * @return list<RecurringSeries>
     */
    public function findConfirmedSpending(User $owner): array
    {
        return $this->owned($owner)
            ->addSelect('c', 'cp')
            ->leftJoin('s.category', 'c')
            ->leftJoin('c.parent', 'cp')
            ->andWhere('s.status = :confirmed')
            ->andWhere('s.income = false')
            ->setParameter('confirmed', SeriesStatus::Confirmed)
            ->orderBy('sortName', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countSuggested(User $owner): int
    {
        return (int) $this->owned($owner)
            ->select('COUNT(s.id)')
            ->andWhere('s.status = :suggested')
            ->setParameter('suggested', SeriesStatus::Suggested)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * With their account and counterparty, and what to sort them by name with: their own, or that of their counterparty.
     */
    private function owned(User $owner): QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->addSelect('a', 'k', 'COALESCE(s.name, k.name, k.key) AS HIDDEN sortName')
            ->join('s.account', 'a')
            ->join('s.counterparty', 'k')
            ->where('a.owner = :owner')
            ->setParameter('owner', $owner);
    }
}
