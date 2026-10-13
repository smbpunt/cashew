<?php

namespace App\Entity;

use App\Repository\CounterpartyRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Who's on the other side of the user's transactions, on any of their accounts: the user names it, and files it in a
 * category, once for all of them, those to come included.
 */
#[ORM\Entity(repositoryClass: CounterpartyRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_COUNTERPARTY_KEY', fields: ['owner', 'key'])]
class Counterparty implements Nameable, Owned
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The name the user gave it.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    /**
     * The category the user filed it in, for all its transactions that have none of their own or of their series.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $category = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $owner,
        /**
         * As CounterpartyNormalizer tells it from the labels: "free mobile".
         */
        #[ORM\Column(length: 255)]
        private string $key,
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

    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * The name the user gave it, or else one made from its key: "Free Mobile".
     */
    public function getName(): string
    {
        return $this->name ?? ucwords($this->key);
    }

    public function isNamed(): bool
    {
        return null !== $this->name;
    }

    public function rename(?string $name): void
    {
        $this->name = '' === trim((string) $name) ? null : trim((string) $name);
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
     * Merged with another one, that tells the same counterparty: it takes the name and the category the user gave the
     * other, unless they gave it its own.
     */
    public function absorb(self $other): void
    {
        $this->name ??= $other->name;
        $this->category ??= $other->category;
    }
}
