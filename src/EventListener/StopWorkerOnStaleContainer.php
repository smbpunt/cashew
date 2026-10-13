<?php

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * Stops the Messenger worker once the code it runs has changed, for Docker to restart it on the new code.
 *
 * A worker runs for up to an hour on the container it booted with. Once the code changes, the next web request
 * compiles another one, and the old one is soon deleted: the worker would then die loading its next service, leaving
 * the message it had taken to wait an hour for its redelivery. Only in dev, where the code changes under it.
 */
#[When(env: 'dev')]
final class StopWorkerOnStaleContainer
{
    /**
     * The compiled container the worker started with, as a hash of the file that loads it.
     */
    private ?string $startedWith = null;

    public function __construct(
        #[Autowire('%kernel.cache_dir%/%kernel.container_class%.php')]
        private readonly string $containerFile,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        $this->startedWith = $this->hash();
    }

    /**
     * After PostgreSqlNotifyOnIdleListener (priority 0), which waits for a message: right before the worker takes it.
     */
    #[AsEventListener(priority: -10)]
    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if (null === $this->startedWith) {
            return;
        }

        // Compiled again by a web request, or its code changed since it was
        if ($this->startedWith === $this->hash() && new ConfigCache($this->containerFile, true)->isFresh()) {
            return;
        }

        $this->logger->info('The code changed since the worker started: stopping it, to be restarted on the new code.');
        $event->getWorker()->stop();
    }

    private function hash(): ?string
    {
        return is_file($this->containerFile) ? (hash_file('xxh128', $this->containerFile) ?: null) : null;
    }
}
