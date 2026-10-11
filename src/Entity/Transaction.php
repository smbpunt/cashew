<?php

namespace App\Entity;

use App\Enum\TransactionKind;
use App\Repository\TransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Table(name: 'bank_transaction')]
#[ORM\UniqueConstraint(name: 'UNIQ_BANK_TRANSACTION_FINGERPRINT', fields: ['account', 'fingerprint'])]
#[ORM\Index(name: 'IDX_BANK_TRANSACTION_DATE', fields: ['account', 'date'])]
class Transaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The recurring series it belongs to, suggested or confirmed.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?RecurringSeries $recurringSeries = null;

    /**
     * The category the user gave it, over that of its series.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $category = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private BankAccount $account,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?StatementImport $statementImport,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $date,
        /**
         * As the bank wrote it, one line per line of the statement.
         */
        #[ORM\Column(type: Types::TEXT)]
        private string $label,
        /**
         * In minor units (cents), negative for a debit.
         */
        #[ORM\Column]
        private int $amount,
        /**
         * ISO 4217 code.
         */
        #[ORM\Column(length: 3)]
        private string $currency,
        #[ORM\Column(enumType: TransactionKind::class)]
        private TransactionKind $kind,
        /**
         * The category the bank gave it, if any.
         */
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $bankCategory,
        /**
         * Identifies the transaction within its account, so that overlapping statements don't import it twice.
         */
        #[ORM\Column(length: 64)]
        private string $fingerprint,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): BankAccount
    {
        return $this->account;
    }

    public function getStatementImport(): ?StatementImport
    {
        return $this->statementImport;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * Its label on one line: "Prélèvement · FREE MOBILE".
     */
    public function getOneLineLabel(): string
    {
        return implode(' · ', explode("\n", $this->label));
    }

    /**
     * What the user knows it as: the name they gave its series, or else its label on one line.
     */
    public function getName(): string
    {
        return $this->isRecurring() ? $this->recurringSeries->getName() : $this->getOneLineLabel();
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getKind(): TransactionKind
    {
        return $this->kind;
    }

    /**
     * A debit imported as regular, from a statement that lacked the card statement it settles: now that its purchases
     * are imported one by one, it must leave the spending totals.
     */
    public function markAsDeferredCardSettlement(): void
    {
        $this->kind = TransactionKind::DeferredCardSettlement;
    }

    public function getBankCategory(): ?string
    {
        return $this->bankCategory;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function getRecurringSeries(): ?RecurringSeries
    {
        return $this->recurringSeries;
    }

    public function attachTo(?RecurringSeries $series): void
    {
        $this->recurringSeries = $series;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function categorize(?Category $category): void
    {
        $this->category = $category;
    }

    /**
     * The category of its series, once the user confirmed it.
     */
    public function getSeriesCategory(): ?Category
    {
        return $this->isRecurring() ? $this->recurringSeries?->getCategory() : null;
    }

    /**
     * The category it counts in: its own, or else that of its series.
     */
    public function getEffectiveCategory(): ?Category
    {
        return $this->category ?? $this->getSeriesCategory();
    }

    /**
     * Whether it's one of a series the user confirmed: a fixed charge, or a regular income.
     */
    public function isRecurring(): bool
    {
        return true === $this->recurringSeries?->isConfirmed();
    }
}
