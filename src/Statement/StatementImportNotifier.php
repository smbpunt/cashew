<?php

namespace App\Statement;

use App\Entity\StatementImport;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\Exception\ExceptionInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells the browser of the import's owner that its status changed, through Mercure.
 */
final readonly class StatementImportNotifier
{
    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public static function topic(StatementImport $import): string
    {
        return \sprintf('/statement-imports/%d', $import->getId());
    }

    public function notify(StatementImport $import): void
    {
        try {
            $this->hub->publish(new Update(self::topic($import), json_encode(['status' => $import->getStatus()->value], \JSON_THROW_ON_ERROR), private: true));
        } catch (ExceptionInterface $exception) {
            // The import itself went fine: the page polls its status anyway, a missed update only delays it
            $this->logger->warning('The status of statement import #{id} couldn\'t be published: {message}', [
                'id' => $import->getId(),
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }
}
