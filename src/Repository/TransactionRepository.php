<?php

namespace App\Repository;

use App\Entity\BankAccount;
use App\Entity\Category;
use App\Entity\Counterparty;
use App\Entity\RecurringSeries;
use App\Entity\StatementImport;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\CategoryRole;
use App\Enum\SeriesStatus;
use App\Enum\TransactionKind;
use App\Time\Month;
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
     * All the owner's transactions, with their counterparty and series.
     *
     * @return list<Transaction>
     */
    public function findOwned(User $owner): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('k', 's')
            ->join('t.account', 'a')
            ->leftJoin('t.counterparty', 'k')
            ->leftJoin('t.recurringSeries', 's')
            ->where('a.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The transactions of the owner's accounts within the month, most recent first, deferred card settlements aside:
     * their purchases are listed one by one, on the day they were made.
     *
     * @return list<Transaction>
     */
    public function findOfMonth(User $owner, Month $month): array
    {
        return $this->withCategories($this->ownedListed($owner))
            ->addSelect('a')
            ->andWhere('t.date >= :start')
            ->andWhere('t.date < :end')
            ->setParameter('start', $month->firstDay(), Types::DATE_IMMUTABLE)
            ->setParameter('end', $month->next()->firstDay(), Types::DATE_IMMUTABLE)
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
        $range = $this->ownedListed($owner)
            ->select('MIN(t.date) AS first', 'MAX(t.date) AS last')
            ->getQuery()
            ->getSingleResult();

        if (null === $range['first'] || null === $range['last']) {
            return null;
        }

        return [new \DateTimeImmutable($range['first']), new \DateTimeImmutable($range['last'])];
    }

    /**
     * The day of the last transaction of each of the owner's accounts that has some: up to when it's known.
     *
     * @return array<int, \DateTimeImmutable> by account id
     */
    public function findLastDates(User $owner): array
    {
        /** @var list<array{account: int|string, last: string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.account) AS account', 'MAX(t.date) AS last')
            ->join('t.account', 'a')
            ->where('a.owner = :owner')
            ->setParameter('owner', $owner)
            ->groupBy('t.account')
            ->getQuery()
            ->getArrayResult();

        $dates = [];
        foreach ($rows as $row) {
            $dates[(int) $row['account']] = new \DateTimeImmutable($row['last']);
        }

        return $dates;
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
            ->andWhere('t.kind NOT IN (:unlisted)')
            ->setParameter('imports', $imports)
            ->setParameter('unlisted', TransactionKind::unlisted())
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
     * The owner's transactions that may come back, oldest first, with their counterparty and series: all but the
     * card settlements, which only add up purchases.
     *
     * @return list<Transaction>
     */
    public function findForRecurrence(User $owner): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('a', 's', 'k')
            ->join('t.account', 'a')
            ->leftJoin('t.recurringSeries', 's')
            ->leftJoin('t.counterparty', 'k')
            ->where('a.owner = :owner')
            ->andWhere('t.kind NOT IN (:nonRecurring)')
            ->setParameter('owner', $owner)
            ->setParameter('nonRecurring', TransactionKind::nonRecurring())
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
     * The transactions of the categories within the period, most recent first: theirs, or else that of their confirmed
     * series, or else that of their counterparty.
     *
     * @param list<Category> $categories
     *
     * @return list<Transaction>
     */
    public function findInCategories(User $owner, array $categories, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->withCategories($this->ownedListed($owner))
            ->addSelect('a')
            ->andWhere(<<<'DQL'
                t.category IN (:categories)
                OR (t.category IS NULL AND s.status = :confirmed AND s.category IN (:categories))
                OR (t.category IS NULL AND (s.id IS NULL OR s.status != :confirmed OR s.category IS NULL) AND k.category IN (:categories))
                DQL)
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
     * The credits, or debits, of the counterparty in the currency, on any of its owner's accounts, most recent first,
     * deferred card settlements aside.
     *
     * @return list<Transaction>
     */
    public function findOfCounterparty(Counterparty $counterparty, string $currency, bool $income): array
    {
        return $this->withCategories($this->ownedListed($counterparty->getOwner()))
            ->addSelect('a')
            ->andWhere('t.counterparty = :counterparty')
            ->andWhere('t.currency = :currency')
            ->andWhere($income ? 't.amount > 0' : 't.amount < 0')
            ->setParameter('counterparty', $counterparty)
            ->setParameter('currency', $currency)
            ->orderBy('t.date', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * How many of the counterparty's transactions go by its name: those that aren't recurring, in no series the user
     * confirmed.
     */
    public function countNamedAfter(Counterparty $counterparty): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->leftJoin('t.recurringSeries', 's')
            ->where('t.counterparty = :counterparty')
            ->andWhere('s.id IS NULL OR s.status != :confirmed')
            ->setParameter('counterparty', $counterparty)
            ->setParameter('confirmed', SeriesStatus::Confirmed)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Loads with them what tells their category: their own, or else that of their series, or else that of their
     * counterparty; and what tells their name.
     */
    private function withCategories(QueryBuilder $queryBuilder): QueryBuilder
    {
        return $queryBuilder
            ->addSelect('c', 'cp', 's', 'sc', 'scp', 'k', 'kc', 'kcp')
            ->leftJoin('t.counterparty', 'k')
            ->leftJoin('k.category', 'kc')
            ->leftJoin('kc.parent', 'kcp')
            ->leftJoin('t.category', 'c')
            ->leftJoin('c.parent', 'cp')
            ->leftJoin('t.recurringSeries', 's')
            ->leftJoin('s.category', 'sc')
            ->leftJoin('sc.parent', 'scp');
    }

    /**
     * The owner's transactions that are listed: a deferred card settlement only repeats the purchases.
     */
    private function ownedListed(User $owner): QueryBuilder
    {
        return $this->createQueryBuilder('t')
            ->join('t.account', 'a')
            ->where('a.owner = :owner')
            ->andWhere('t.kind NOT IN (:unlisted)')
            ->setParameter('owner', $owner)
            ->setParameter('unlisted', TransactionKind::unlisted());
    }
}
