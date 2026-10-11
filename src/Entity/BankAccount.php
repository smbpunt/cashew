<?php

namespace App\Entity;

use App\Enum\Bank;
use App\Repository\BankAccountRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An account as the bank's statements name it, created on the fly by the first import that mentions it.
 */
#[ORM\Entity(repositoryClass: BankAccountRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_BANK_ACCOUNT_EXTERNAL_ID', fields: ['owner', 'bank', 'externalId'])]
class BankAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $owner,
        #[ORM\Column(enumType: Bank::class)]
        private Bank $bank,
        /**
         * What identifies the account within the bank's statements (account number, account name...).
         */
        #[ORM\Column(length: 255)]
        private string $externalId,
        #[ORM\Column(length: 255)]
        private string $label,
        /**
         * ISO 4217 code.
         */
        #[ORM\Column(length: 3)]
        private string $currency,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getBank(): Bank
    {
        return $this->bank;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }
}
