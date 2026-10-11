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
            ->addOrderBy('s.name', 'ASC')
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

    private function owned(User $owner): QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->addSelect('a')
            ->join('s.account', 'a')
            ->where('a.owner = :owner')
            ->setParameter('owner', $owner);
    }
}
