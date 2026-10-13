<?php

namespace App\Entity;

use App\Enum\Bank;
use App\Enum\ImportStatus;
use App\Repository\StatementImportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * One uploaded statement file, and how its asynchronous import went.
 */
#[ORM\Entity(repositoryClass: StatementImportRepository::class)]
#[ORM\Index(name: 'IDX_STATEMENT_IMPORT_CHECKSUM', fields: ['owner', 'checksum'])]
class StatementImport implements Owned
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: ImportStatus::class)]
    private ImportStatus $status = ImportStatus::Pending;

    #[ORM\Column(nullable: true, enumType: Bank::class)]
    private ?Bank $bank = null;

    #[ORM\Column]
    private int $importedCount = 0;

    /**
     * Transactions already known, from an overlapping statement.
     */
    #[ORM\Column]
    private int $skippedCount = 0;

    /**
     * Transactions after the cutoff date: the statement of their month will import them.
     */
    #[ORM\Column]
    private int $afterCutoffCount = 0;

    /**
     * Why the import failed, as a translation key.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $errorKey = null;

    /**
     * The parameters of the error's translation.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $errorData = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $owner,
        #[ORM\Column(length: 255)]
        private string $originalFilename,
        /**
         * Where the uploaded file waits for the worker; null once it's processed and deleted.
         */
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $storedPath,
        /**
         * SHA-256 of the file, to refuse the same file twice.
         */
        #[ORM\Column(length: 64)]
        private string $checksum,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
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

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function getStoredPath(): ?string
    {
        return $this->storedPath;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStatus(): ImportStatus
    {
        return $this->status;
    }

    public function getBank(): ?Bank
    {
        return $this->bank;
    }

    public function getImportedCount(): int
    {
        return $this->importedCount;
    }

    public function getSkippedCount(): int
    {
        return $this->skippedCount;
    }

    public function getAfterCutoffCount(): int
    {
        return $this->afterCutoffCount;
    }

    public function getError(): ?TranslatableMessage
    {
        return null === $this->errorKey ? null : new TranslatableMessage($this->errorKey, $this->errorData);
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function start(): void
    {
        $this->status = ImportStatus::Processing;
    }

    public function complete(Bank $bank, int $importedCount, int $skippedCount, int $afterCutoffCount, \DateTimeImmutable $finishedAt): void
    {
        $this->status = ImportStatus::Done;
        $this->bank = $bank;
        $this->importedCount = $importedCount;
        $this->skippedCount = $skippedCount;
        $this->afterCutoffCount = $afterCutoffCount;
        $this->finishedAt = $finishedAt;
    }

    /**
     * @param array<string, string> $errorData
     */
    public function fail(string $errorKey, array $errorData, \DateTimeImmutable $finishedAt): void
    {
        $this->status = ImportStatus::Failed;
        $this->errorKey = $errorKey;
        $this->errorData = $errorData;
        $this->finishedAt = $finishedAt;
    }

    public function forgetStoredFile(): void
    {
        $this->storedPath = null;
    }
}
