<?php

namespace App\Repository;

use App\Entity\BankAccount;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\TransactionKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
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

    /**
     * The transactions of the owner's accounts within the month, most recent first, deferred card settlements aside:
     * their purchases are listed one by one, on the day they were made.
     *
     * @return list<Transaction>
     */
    public function findOfMonth(User $owner, \DateTimeImmutable $month): array
    {
        $start = $month->modify('first day of this month')->setTime(0, 0);

        return $this->ownedWithoutSettlements($owner)
            ->addSelect('a')
            ->andWhere('t.date >= :start')
            ->andWhere('t.date < :end')
            ->setParameter('start', $start, Types::DATE_IMMUTABLE)
            ->setParameter('end', $start->modify('+1 month'), Types::DATE_IMMUTABLE)
            ->orderBy('t.date', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The days of the owner's first and last transactions, deferred card settlements aside; null without any.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}|null
     */
    public function findDateRange(User $owner): ?array
    {
        /** @var array{first: ?string, last: ?string} $range */
        $range = $this->ownedWithoutSettlements($owner)
            ->select('MIN(t.date) AS first', 'MAX(t.date) AS last')
            ->getQuery()
            ->getSingleResult();

        if (null === $range['first'] || null === $range['last']) {
            return null;
        }

        return [new \DateTimeImmutable($range['first']), new \DateTimeImmutable($range['last'])];
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

    private function ownedWithoutSettlements(User $owner): QueryBuilder
    {
        return $this->createQueryBuilder('t')
            ->join('t.account', 'a')
            ->where('a.owner = :owner')
            ->andWhere('t.kind != :settlement')
            ->setParameter('owner', $owner)
            ->setParameter('settlement', TransactionKind::DeferredCardSettlement);
    }
}
