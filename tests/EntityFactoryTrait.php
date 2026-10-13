<?php

namespace App\Tests;

use App\Entity\BankAccount;
use App\Entity\Category;
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
        $transaction = new Transaction($account, $import, new \DateTimeImmutable($date), $label, $amount, $account->getCurrency(), $kind, null, bin2hex(random_bytes(32)));
        $this->entityManager->persist($transaction);

        return $transaction;
    }

    /**
     * A series named as the user confirmed it by default, of credits for a positive amount.
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
        $series = new RecurringSeries($account, mb_strtolower($name), $amount > 0, $name, $periodicity, $amount, 90, new \DateTimeImmutable($lastSeenOn));
        match ($status) {
            SeriesStatus::Confirmed => $series->confirm($name, $category),
            SeriesStatus::Rejected => $series->reject(),
            SeriesStatus::Suggested => null,
        };
        $this->entityManager->persist($series);

        return $series;
    }
}
