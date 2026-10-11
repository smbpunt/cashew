<?php

namespace App\MessageHandler;

use App\Entity\StatementImport;
use App\Enum\ImportStatus;
use App\Message\ImportStatement;
use App\Repository\StatementImportRepository;
use App\Statement\Exception\UnreadableStatementException;
use App\Statement\Import\StatementImporter;
use App\Statement\StatementImportNotifier;
use App\Statement\StatementStorage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs an import, and records how it went. Never retried: a failed import is for the user to upload again.
 */
#[AsMessageHandler]
final readonly class ImportStatementHandler
{
    public function __construct(
        private StatementImportRepository $imports,
        private StatementImporter $importer,
        private StatementStorage $storage,
        private StatementImportNotifier $notifier,
        private ManagerRegistry $doctrine,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ImportStatement $message): void
    {
        $import = $this->imports->find($message->statementImportId);

        // Deleted since, or a redelivered message
        if (null === $import || ImportStatus::Pending !== $import->getStatus()) {
            return;
        }

        // One import at a time per user: two overlapping statements imported at once would both miss each other's transactions
        $lock = $this->lockFactory->createLock(\sprintf('statement-import-%d', $import->getOwner()->getId()), ttl: 600);
        $lock->acquire(true);

        try {
            $import->start();
            $this->entityManager()->flush();
            $this->notifier->notify($import);

            $import = $this->run($import);
            $this->notifier->notify($import);
        } finally {
            $lock->release();
        }
    }

    private function run(StatementImport $import): StatementImport
    {
        $id = $import->getId();
        $path = $import->getStoredPath();

        try {
            $result = $this->importer->import($import);
            $import->complete($result->bank, $result->importedCount, $result->skippedCount, $result->afterCutoffCount, $this->clock->now());
            $this->entityManager()->flush();
        } catch (\Throwable $exception) {
            $import = $this->reload($id) ?? throw $exception;

            if ($exception instanceof UnreadableStatementException) {
                $import->fail($exception->getMessage(), $this->clock->now());
            } else {
                $this->logger->error('Statement import #{id} failed: {message}', ['id' => $id, 'message' => $exception->getMessage(), 'exception' => $exception]);
                $import->fail('Something went wrong while importing this file.', $this->clock->now());
            }
        }

        // Bank statements are personal data: keep them no longer than needed, whatever the outcome
        if (null !== $path) {
            $this->storage->delete($path);
            $import->forgetStoredFile();
        }
        $this->entityManager()->flush();

        return $import;
    }

    /**
     * Drops whatever the failed import persisted, and loads the import again.
     */
    private function reload(?int $id): ?StatementImport
    {
        $entityManager = $this->entityManager();

        // A database error closes the entity manager for good
        if (!$entityManager->isOpen()) {
            $this->doctrine->resetManager();
            $entityManager = $this->entityManager();
        } else {
            $entityManager->clear();
        }

        return null === $id ? null : $entityManager->find(StatementImport::class, $id);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->doctrine->getManagerForClass(StatementImport::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
