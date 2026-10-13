<?php

namespace App\Repository;

use App\Entity\BankAccount;
use App\Entity\Category;
use App\Entity\RecurringSeries;
use App\Entity\StatementImport;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\CategoryRole;
use App\Enum\SeriesStatus;
use App\Enum\TransactionKind;
use App\Report\Month;
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
        $start = Month::firstDayOf($month);

        return $this->withCategories($this->ownedWithoutSettlements($owner))
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

    /**
     * The days of the first and last transactions each import brought, deferred card settlements aside; none for an
     * import that brought none.
     *
     * @param list<StatementImport> $imports
     *
     * @return array<int, array{\DateTimeImmutable, \DateTimeImmutable}> by import id
     */
    public function findDateRangesByImport(array $imports): array
    {
        if ([] === $imports) {
            return [];
        }

        /** @var list<array{import: int|string, first: string, last: string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.statementImport) AS import', 'MIN(t.date) AS first', 'MAX(t.date) AS last')
            ->where('t.statementImport IN (:imports)')
            ->andWhere('t.kind != :settlement')
            ->setParameter('imports', $imports)
            ->setParameter('settlement', TransactionKind::DeferredCardSettlement)
            ->groupBy('t.statementImport')
            ->getQuery()
            ->getArrayResult();

        $ranges = [];
        foreach ($rows as $row) {
            $ranges[(int) $row['import']] = [new \DateTimeImmutable($row['first']), new \DateTimeImmutable($row['last'])];
        }

        return $ranges;
    }

    /**
     * The owner's transactions that may come back, oldest first, with their series: all but the deferred card
     * settlements, which only add up purchases.
     *
     * @return list<Transaction>
     */
    public function findForRecurrence(User $owner): array
    {
        return $this->ownedWithoutSettlements($owner)
            ->addSelect('a', 's')
            ->leftJoin('t.recurringSeries', 's')
            ->orderBy('t.date', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every transaction of the confirmed series that the imports added to, oldest first.
     *
     * @param list<StatementImport> $imports
     *
     * @return list<Transaction>
     */
    public function findOfConfirmedSeriesImportedBy(array $imports): array
    {
        if ([] === $imports) {
            return [];
        }

        $series = $this->createQueryBuilder('imported')
            ->select('IDENTITY(imported.recurringSeries)')
            ->where('imported.statementImport IN (:imports)');

        return $this->createQueryBuilder('t')
            ->addSelect('s')
            ->join('t.recurringSeries', 's')
            ->where('s.status = :confirmed')
            ->andWhere($this->getEntityManager()->getExpressionBuilder()->in('s.id', $series->getDQL()))
            ->setParameter('confirmed', SeriesStatus::Confirmed)
            ->setParameter('imports', $imports)
            ->orderBy('t.date', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The owner's regular transactions within the period, both days included, with their account and series: those
     * that may be one side of money moved between two of their accounts.
     *
     * @return list<Transaction>
     */
    public function findForTransfers(User $owner, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('a', 's')
            ->join('t.account', 'a')
            ->leftJoin('t.recurringSeries', 's')
            ->where('a.owner = :owner')
            ->andWhere('t.kind = :regular')
            ->andWhere('t.date >= :start')
            ->andWhere('t.date <= :end')
            ->setParameter('owner', $owner)
            ->setParameter('regular', TransactionKind::Regular)
            ->setParameter('start', $start, Types::DATE_IMMUTABLE)
            ->setParameter('end', $end, Types::DATE_IMMUTABLE)
            ->orderBy('t.date', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * What the imports brought that's filed in the "Transfers" category, most recent first.
     *
     * @param list<StatementImport> $imports
     *
     * @return list<Transaction>
     */
    public function findTransfersImportedBy(array $imports): array
    {
        if ([] === $imports) {
            return [];
        }

        return $this->withCategories($this->createQueryBuilder('t'))
            ->addSelect('a')
            ->join('t.account', 'a')
            ->where('t.statementImport IN (:imports)')
            ->andWhere('c.role = :transfers')
            ->setParameter('imports', $imports)
            ->setParameter('transfers', CategoryRole::Transfers)
            ->orderBy('t.date', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();
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

    /**
     * The transactions of the categories, or of their confirmed series, within the period, most recent first.
     *
     * @param list<Category> $categories
     *
     * @return list<Transaction>
     */
    public function findInCategories(User $owner, array $categories, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->withCategories($this->ownedWithoutSettlements($owner))
            ->addSelect('a')
            ->andWhere('t.category IN (:categories) OR (t.category IS NULL AND s.status = :confirmed AND s.category IN (:categories))')
            ->andWhere('t.date >= :start')
            ->andWhere('t.date < :end')
            ->setParameter('categories', $categories)
            ->setParameter('confirmed', SeriesStatus::Confirmed)
            ->setParameter('start', $start, Types::DATE_IMMUTABLE)
            ->setParameter('end', $end, Types::DATE_IMMUTABLE)
            ->orderBy('t.date', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The transactions of the series, most recent first.
     *
     * @return list<Transaction>
     */
    public function findOfSeries(RecurringSeries $series): array
    {
        return $this->withCategories($this->createQueryBuilder('t'))
            ->addSelect('a')
            ->join('t.account', 'a')
            ->where('t.recurringSeries = :series')
            ->setParameter('series', $series)
            ->orderBy('t.date', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The owner's credits, or debits, in the currency, most recent first, deferred card settlements aside: those
     * among which to find the other transactions of a counterparty.
     *
     * @return list<Transaction>
     */
    public function findOfDirection(User $owner, string $currency, bool $income): array
    {
        return $this->withCategories($this->ownedWithoutSettlements($owner))
            ->addSelect('a')
            ->andWhere('t.currency = :currency')
            ->andWhere($income ? 't.amount > 0' : 't.amount < 0')
            ->setParameter('currency', $currency)
            ->orderBy('t.date', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Loads with them what tells their category: their own, or that of their series.
     */
    private function withCategories(QueryBuilder $queryBuilder): QueryBuilder
    {
        return $queryBuilder
            ->addSelect('c', 'cp', 's', 'sc', 'scp')
            ->leftJoin('t.category', 'c')
            ->leftJoin('c.parent', 'cp')
            ->leftJoin('t.recurringSeries', 's')
            ->leftJoin('s.category', 'sc')
            ->leftJoin('sc.parent', 'scp');
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
