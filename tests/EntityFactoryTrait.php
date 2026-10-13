<?php

namespace App\Tests;

use App\Counterparty\CounterpartyNormalizer;
use App\Entity\BankAccount;
use App\Entity\Category;
use App\Entity\Counterparty;
use App\Entity\RecurringSeries;
use App\Entity\StatementImport;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Bank;
use App\Enum\Periodicity;
use App\Enum\SeriesStatus;
use App\Enum\TransactionKind;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates the entities a test needs, persisted through its entity manager: users are flushed right away, the others
 * with the next flush.
 */
trait EntityFactoryTrait
{
    private EntityManagerInterface $entityManager;

    /**
     * @var array<string, Counterparty>
     */
    private array $counterparties = [];

    /**
     * Takes the entity manager of the kernel, and empties the database: deleting the users deletes all they own.
     */
    private function resetDatabase(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();
    }

    private function createUser(string $login): User
    {
        $user = new User()->setLogin($login)->setPassword('not-a-real-hash');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createAccount(User $owner, Bank $bank = Bank::CreditAgricole, string $externalId = '123', string $label = 'Compte de Dépôt', string $currency = 'EUR'): BankAccount
    {
        $account = new BankAccount($owner, $bank, $externalId, $label, $currency);
        $this->entityManager->persist($account);

        return $account;
    }

    /**
     * In progress, its checksum that of its filename.
     */
    private function createImport(User $owner, string $filename, ?string $storedPath = null): StatementImport
    {
        $import = new StatementImport($owner, $filename, $storedPath, hash('sha256', $filename), new \DateTimeImmutable());
        $this->entityManager->persist($import);

        return $import;
    }

    private function createCategory(User $owner, string $name, ?Category $parent = null): Category
    {
        $category = new Category($owner)->setName($name)->setParent($parent);
        $this->entityManager->persist($category);

        return $category;
    }

    private function createTransaction(
        BankAccount $account,
        string $date,
        string $label,
        int $amount,
        TransactionKind $kind = TransactionKind::Regular,
        ?StatementImport $import = null,
    ): Transaction {
        $key = new CounterpartyNormalizer()->normalize($label);
        $counterparty = '' === $key ? null : $this->createCounterparty($account->getOwner(), $key);
        $transaction = new Transaction($account, $import, new \DateTimeImmutable($date), $label, $counterparty, $amount, $account->getCurrency(), $kind, null, bin2hex(random_bytes(32)));
        $this->entityManager->persist($transaction);

        return $transaction;
    }

    /**
     * The owner's counterparty of the key, created the first time: the one the transactions of its labels have.
     */
    private function createCounterparty(User $owner, string $key): Counterparty
    {
        $cacheKey = spl_object_id($owner)."\0".$key;
        if (!isset($this->counterparties[$cacheKey]) || !$this->entityManager->contains($this->counterparties[$cacheKey])) {
            $this->counterparties[$cacheKey] = $this->entityManager->getRepository(Counterparty::class)->findOneBy(['owner' => $owner, 'key' => $key])
                ?? new Counterparty($owner, $key);
            $this->entityManager->persist($this->counterparties[$cacheKey]);
        }

        return $this->counterparties[$cacheKey];
    }

    /**
     * A series named as the user confirmed it by default, of credits for a positive amount, of the counterparty of
     * its name in lowercase.
     */
    private function createSeries(
        BankAccount $account,
        string $name,
        int $amount,
        string $lastSeenOn,
        Periodicity $periodicity = Periodicity::Monthly,
        SeriesStatus $status = SeriesStatus::Confirmed,
        ?Category $category = null,
    ): RecurringSeries {
        $series = new RecurringSeries($account, $this->createCounterparty($account->getOwner(), mb_strtolower($name)), $amount > 0, $periodicity, $amount, 90, new \DateTimeImmutable($lastSeenOn));
        match ($status) {
            SeriesStatus::Confirmed => $series->confirm($name, $category),
            SeriesStatus::Rejected => $series->reject(),
            SeriesStatus::Suggested => null,
        };
        $this->entityManager->persist($series);

        return $series;
    }
}
