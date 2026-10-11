<?php

namespace App\Statement\Import;

use App\Entity\BankAccount;
use App\Entity\User;
use App\Enum\Bank;
use App\Repository\BankAccountRepository;
use App\Statement\AccountRef;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds the account a statement names, or creates it.
 */
final class AccountResolver
{
    /**
     * @var array<string, BankAccount>
     */
    private array $accounts = [];

    public function __construct(
        private readonly BankAccountRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function resolve(User $owner, Bank $bank, AccountRef $ref): BankAccount
    {
        $key = implode("\0", [$owner->getId(), $bank->value, $ref->externalId]);

        if (!isset($this->accounts[$key]) || !$this->entityManager->contains($this->accounts[$key])) {
            $this->accounts[$key] = $this->repository->findOneBy(['owner' => $owner, 'bank' => $bank, 'externalId' => $ref->externalId])
                ?? $this->create($owner, $bank, $ref);
        }

        return $this->accounts[$key];
    }

    private function create(User $owner, Bank $bank, AccountRef $ref): BankAccount
    {
        $account = new BankAccount($owner, $bank, $ref->externalId, $ref->label, $ref->currency);
        $this->entityManager->persist($account);

        return $account;
    }
}
