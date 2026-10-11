<?php

namespace App\Repository;

use App\Entity\StatementImport;
use App\Entity\User;
use App\Enum\ImportStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StatementImport>
 */
class StatementImportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StatementImport::class);
    }

    /**
     * Whether the file is already imported, or being imported: a failed import doesn't count, so that it can be retried.
     */
    public function hasActiveImport(User $owner, string $checksum): bool
    {
        return null !== $this->createQueryBuilder('i')
            ->select('i.id')
            ->where('i.owner = :owner')
            ->andWhere('i.checksum = :checksum')
            ->andWhere('i.status != :failed')
            ->setParameter('owner', $owner)
            ->setParameter('checksum', $checksum)
            ->setParameter('failed', ImportStatus::Failed)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<int> $ids
     *
     * @return list<StatementImport> most recent first
     */
    public function findOwned(User $owner, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('i')
            ->where('i.owner = :owner')
            ->andWhere('i.id IN (:ids)')
            ->setParameter('owner', $owner)
            ->setParameter('ids', $ids)
            ->orderBy('i.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The owner's imports still waiting for the worker, or being run, oldest first.
     *
     * @return list<StatementImport>
     */
    public function findInProgress(User $owner): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.owner = :owner')
            ->andWhere('i.status IN (:statuses)')
            ->setParameter('owner', $owner)
            ->setParameter('statuses', [ImportStatus::Pending, ImportStatus::Processing])
            ->orderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function hasDone(User $owner): bool
    {
        return null !== $this->createQueryBuilder('i')
            ->select('i.id')
            ->where('i.owner = :owner')
            ->andWhere('i.status = :done')
            ->setParameter('owner', $owner)
            ->setParameter('done', ImportStatus::Done)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
