<?php

namespace App\Entity;

use App\Enum\CategoryRole;
use App\Repository\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * What the user spends on, or earns from: "Home", or within it, "Energy". Two levels at most, for the totals of a
 * category to be those of its subcategories.
 */
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_CATEGORY_NAME', fields: ['owner', 'parent', 'name'])]
#[ORM\UniqueConstraint(name: 'UNIQ_CATEGORY_ROLE', fields: ['owner', 'role'])]
#[UniqueEntity(fields: ['owner', 'parent', 'name'], message: 'category.name.taken', errorPath: 'name', ignoreNull: false)]
class Category implements Owned
{
    /**
     * A Lucide icon, as UX Icons names it: "lucide:house".
     */
    public const string ICON_PATTERN = '/^lucide:[a-z0-9]+(?:-[a-z0-9]+)*$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name = '';

    #[ORM\Column(length: 64, nullable: true)]
    #[Assert\Length(max: 64)]
    #[Assert\Regex(pattern: self::ICON_PATTERN, message: 'category.icon.invalid')]
    private ?string $icon = null;

    /**
     * What the application relies on it for, if anything: then it's a system category, that can't be deleted.
     */
    #[ORM\Column(nullable: true, enumType: CategoryRole::class)]
    private ?CategoryRole $role = null;

    /**
     * The key of the default category it was created as, "energy" for instance; null for those the user made.
     */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $defaultKey = null;

    #[ORM\ManyToOne(inversedBy: 'children')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Category $parent = null;

    /**
     * @var Collection<int, Category>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $children;

    /**
     * @var array<string, \Collator> by locale: one is costly to create, and the user's language changes with the request
     */
    private static array $collators = [];

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $owner,
    ) {
        $this->children = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = trim((string) $name);

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = '' === trim((string) $icon) ? null : trim((string) $icon);

        return $this;
    }

    /**
     * Its own icon, or else that of its parent: "Energy" shows the house of "Home" until it has one.
     */
    public function getDisplayIcon(): ?string
    {
        return $this->icon ?? $this->parent?->getIcon();
    }

    public function getRole(): ?CategoryRole
    {
        return $this->role;
    }

    public function setRole(?CategoryRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getDefaultKey(): ?string
    {
        return $this->defaultKey;
    }

    public function setDefaultKey(?string $defaultKey): static
    {
        $this->defaultKey = $defaultKey;

        return $this;
    }

    /**
     * Whether the application relies on it: the user may rename it, not delete it nor put it in another one.
     */
    public function isSystem(): bool
    {
        return null !== $this->role;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        // Both sides, for the parents loaded already to list it right away
        $this->parent?->children->removeElement($this);
        $this->parent = $parent;
        if (null !== $parent && !$parent->children->contains($this)) {
            $parent->children->add($this);
        }

        return $this;
    }

    /**
     * By name, as the user's language sorts them.
     *
     * @return list<Category>
     */
    public function getChildren(): array
    {
        return self::sortByName($this->children);
    }

    /**
     * By name, accents aside: "Énergie" before "Internet", whatever the database's collation.
     *
     * @param iterable<Category> $categories
     *
     * @return list<Category>
     */
    public static function sortByName(iterable $categories): array
    {
        $collator = self::$collators[\Locale::getDefault()] ??= new \Collator(\Locale::getDefault());
        $categories = [...$categories];
        usort($categories, static fn (Category $a, Category $b): int => (int) $collator->compare($a->getName(), $b->getName()));

        return $categories;
    }

    /**
     * The category itself if it's at the top, its parent otherwise: where its totals add up.
     */
    public function getRoot(): self
    {
        return $this->parent ?? $this;
    }

    /**
     * "Home › Energy".
     */
    public function getPath(): string
    {
        return null === $this->parent ? $this->name : $this->parent->getName().' › '.$this->name;
    }

    #[Assert\Callback]
    public function validateParent(ExecutionContextInterface $context): void
    {
        if (null === $this->parent) {
            return;
        }

        if ($this->parent === $this || $this->parent->getOwner() !== $this->owner || $this->isSystem()) {
            $context->buildViolation('category.parent.invalid')->atPath('parent')->addViolation();
        } elseif (null !== $this->parent->getParent() || !$this->children->isEmpty()) {
            $context->buildViolation('category.parent.too_deep')->atPath('parent')->addViolation();
        }
    }
}
