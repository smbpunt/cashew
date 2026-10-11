<?php

namespace App\Repository;

use App\Entity\BankAccount;
use App\Entity\Transaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /**
     * @return list<string>
     */
    public function findFingerprints(BankAccount $account): array
    {
        return $this->createQueryBuilder('t')
            ->select('t.fingerprint')
            ->where('t.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleColumnResult();
    }

    public function hasAny(User $owner): bool
    {
        return null !== $this->createQueryBuilder('t')
            ->select('t.id')
            ->join('t.account', 'a')
            ->where('a.owner = :owner')
            ->setParameter('owner', $owner)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
