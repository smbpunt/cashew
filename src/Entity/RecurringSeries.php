<?php

namespace App\Entity;

use App\Enum\Periodicity;
use App\Enum\SeriesStatus;
use App\Repository\RecurringSeriesRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Transactions of an account that come back with the same counterparty, and contract if it has several, on a regular basis: a subscription, a
 * salary, a loan... Detected on each import, then reviewed by the user.
 */
#[ORM\Entity(repositoryClass: RecurringSeriesRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_RECURRING_SERIES_COUNTERPARTY', fields: ['account', 'counterparty', 'reference', 'income'])]
class RecurringSeries
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: SeriesStatus::class)]
    private SeriesStatus $status = SeriesStatus::Suggested;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $category = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private BankAccount $account,
        /**
         * Who's on the other side, as CounterpartyNormalizer tells it from the label.
         */
        #[ORM\Column(length: 255)]
        private string $counterparty,
        /**
         * Whether its transactions are credits: a refund from a shop isn't one of its purchases.
         */
        #[ORM\Column]
        private bool $income,
        #[ORM\Column(length: 255)]
        private string $name,
        #[ORM\Column(enumType: Periodicity::class)]
        private Periodicity $periodicity,
        /**
         * The median amount of the last occurrences, in minor units (cents), negative for a debit.
         */
        #[ORM\Column]
        private int $typicalAmount,
        /**
         * How sure the detection is that it's one, from 0 to 100.
         */
        #[ORM\Column]
        private int $confidence,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $lastSeenOn,
        /**
         * The contract it's of, when the counterparty has several at once, as ReferenceSplitter tells it; empty otherwise.
         */
        #[ORM\Column(length: 64, options: ['default' => ''])]
        private string $reference = '',
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

    public function getOwner(): User
    {
        return $this->account->getOwner();
    }

    public function getCounterparty(): string
    {
        return $this->counterparty;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function isIncome(): bool
    {
        return $this->income;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStatus(): SeriesStatus
    {
        return $this->status;
    }

    public function isConfirmed(): bool
    {
        return SeriesStatus::Confirmed === $this->status;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function getPeriodicity(): Periodicity
    {
        return $this->periodicity;
    }

    public function getTypicalAmount(): int
    {
        return $this->typicalAmount;
    }

    public function getCurrency(): string
    {
        return $this->account->getCurrency();
    }

    public function getConfidence(): int
    {
        return $this->confidence;
    }

    public function getLastSeenOn(): \DateTimeImmutable
    {
        return $this->lastSeenOn;
    }

    public function confirm(string $name, ?Category $category): void
    {
        $this->status = SeriesStatus::Confirmed;
        $this->name = $name;
        $this->category = $category;
    }

    public function reject(): void
    {
        $this->status = SeriesStatus::Rejected;
    }

    public function refresh(Periodicity $periodicity, int $typicalAmount, int $confidence, \DateTimeImmutable $lastSeenOn): void
    {
        $this->periodicity = $periodicity;
        $this->typicalAmount = $typicalAmount;
        $this->confidence = $confidence;
        $this->lastSeenOn = $lastSeenOn;
    }
}
