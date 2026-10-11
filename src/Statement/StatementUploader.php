<?php

namespace App\Statement;

use App\Entity\StatementImport;
use App\Entity\User;
use App\Message\ImportStatement;
use App\Repository\StatementImportRepository;
use App\Statement\Exception\DuplicateStatementException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Takes an uploaded statement in, and leaves its import to the Messenger worker.
 */
final readonly class StatementUploader
{
    public function __construct(
        private StatementStorage $storage,
        private StatementImportRepository $imports,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DuplicateStatementException when the same file is already imported, or being imported
     */
    public function upload(User $owner, UploadedFile $file): StatementImport
    {
        $checksum = hash_file('sha256', $file->getPathname()) ?: throw new \RuntimeException('The uploaded file can\'t be read.');

        if ($this->imports->hasActiveImport($owner, $checksum)) {
            throw new DuplicateStatementException(\sprintf('"%s" is already imported.', $file->getClientOriginalName()));
        }

        $import = new StatementImport($owner, $file->getClientOriginalName(), $this->storage->store($file), $checksum, $this->clock->now());
        $this->entityManager->persist($import);
        $this->entityManager->flush();

        $this->bus->dispatch(new ImportStatement((int) $import->getId()));

        return $import;
    }
}
