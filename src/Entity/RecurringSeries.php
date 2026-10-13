<?php

namespace App\Entity;

use App\Enum\Periodicity;
use App\Enum\SeriesStatus;
use App\Repository\RecurringSeriesRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Transactions of an account that come back with the same counterparty, and contract if it has several, on a regular
 * basis: a subscription, a salary, a loan... Detected on each import, then reviewed by the user.
 */
#[ORM\Entity(repositoryClass: RecurringSeriesRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_RECURRING_SERIES_COUNTERPARTY', fields: ['account', 'counterparty', 'reference', 'income'])]
class RecurringSeries implements Nameable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: SeriesStatus::class)]
    private SeriesStatus $status = SeriesStatus::Suggested;

    /**
     * The name the user gave it, over that of its counterparty: "Prime", from "Amazon".
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $category = null;

    /**
     * The day the user said it ended: it's no longer expected.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedOn = null;

    /**
     * Up to when the user said it still goes on, though an occurrence was missing.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $stillActiveOn = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private BankAccount $account,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Counterparty $counterparty,
        /**
         * Whether its transactions are credits: a refund from a shop isn't one of its purchases.
         */
        #[ORM\Column]
        private bool $income,
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

    public function getCounterparty(): Counterparty
    {
        return $this->counterparty;
    }

    /**
     * Its transactions tell another counterparty, now that they're told apart otherwise.
     */
    public function assignTo(Counterparty $counterparty): void
    {
        $this->counterparty = $counterparty;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function isIncome(): bool
    {
        return $this->income;
    }

    /**
     * The name the user gave it, or else that of its counterparty, and of its contract if it has several: "Generali ·
     * AU856955".
     */
    public function getName(): string
    {
        return $this->name ?? $this->getDefaultName();
    }

    /**
     * A name the same as that of its counterparty isn't one of its own: it follows the counterparty's, as a blank one
     * does.
     */
    public function rename(?string $name): void
    {
        $name = trim((string) $name);
        $this->name = '' === $name || $name === $this->getDefaultName() ? null : $name;
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

    public function isEnded(): bool
    {
        return null !== $this->endedOn;
    }

    public function getEndedOn(): ?\DateTimeImmutable
    {
        return $this->endedOn;
    }

    public function getStillActiveOn(): ?\DateTimeImmutable
    {
        return $this->stillActiveOn;
    }

    public function confirm(string $name, ?Category $category): void
    {
        $this->status = SeriesStatus::Confirmed;
        $this->rename($name);
        $this->category = $category;
    }

    public function reject(): void
    {
        $this->status = SeriesStatus::Rejected;
    }

    public function end(\DateTimeImmutable $on): void
    {
        $this->endedOn = $on;
    }

    public function resume(): void
    {
        $this->endedOn = null;
    }

    /**
     * Still going on as of that day, though an occurrence was missing: until another is.
     */
    public function keepActive(\DateTimeImmutable $on): void
    {
        $this->stillActiveOn = $on;
    }

    /**
     * An occurrence after the day it ended tells it goes on, or started again: the last one before, its last.
     */
    public function refresh(Periodicity $periodicity, int $typicalAmount, int $confidence, \DateTimeImmutable $lastSeenOn): void
    {
        $this->periodicity = $periodicity;
        $this->typicalAmount = $typicalAmount;
        $this->confidence = $confidence;
        $this->lastSeenOn = $lastSeenOn;

        if (null !== $this->endedOn && $lastSeenOn->format('Y-m-d') > $this->endedOn->format('Y-m-d')) {
            $this->endedOn = null;
        }
    }

    private function getDefaultName(): string
    {
        return $this->counterparty->getName().('' === $this->reference ? '' : ' · '.strtoupper($this->reference));
    }
}
