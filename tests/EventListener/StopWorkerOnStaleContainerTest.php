<?php

namespace App\Tests\EventListener;

use App\EventListener\StopWorkerOnStaleContainer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Worker;

/**
 * Registered in dev only: runs a worker of its own, on a container file of its own.
 */
final class StopWorkerOnStaleContainerTest extends TestCase
{
    private const int MAX_TICKS = 3;

    private string $dir;
    private string $containerFile;
    private string $source;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/'.uniqid('stale-container-', true);
        mkdir($this->dir);
        $this->containerFile = $this->dir.'/App_KernelDevDebugContainer.php';
        $this->source = $this->dir.'/Service.php';

        file_put_contents($this->source, '<?php');
        touch($this->source, time() - 60);
        new ConfigCache($this->containerFile, true)->write("<?php return 'Container1';", [new FileResource($this->source)]);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    public function testKeepsTheWorkerRunningWhileItsCodeIsUnchanged(): void
    {
        self::assertSame(self::MAX_TICKS, $this->runWorker(static function (): void {
        }));
    }

    public function testStopsTheWorkerOnceAWebRequestCompiledTheContainerAgain(): void
    {
        self::assertSame(1, $this->runWorker(function (): void {
            new ConfigCache($this->containerFile, true)->write("<?php return 'Container2';", [new FileResource($this->source)]);
        }));
    }

    public function testStopsTheWorkerOnceItsCodeChangedBeforeTheContainerIsCompiledAgain(): void
    {
        self::assertSame(1, $this->runWorker(function (): void {
            touch($this->source, time() + 60);
        }));
    }

    /**
     * Runs a worker without receivers, which changes things at its first tick, and stops it after a few at most.
     *
     * @return int the ticks it ran for
     */
    private function runWorker(\Closure $change): int
    {
        $dispatcher = new EventDispatcher();
        $listener = new StopWorkerOnStaleContainer($this->containerFile, new NullLogger());
        $dispatcher->addListener(WorkerStartedEvent::class, $listener->onWorkerStarted(...));
        $dispatcher->addListener(WorkerRunningEvent::class, $listener->onWorkerRunning(...), -10);

        $ticks = 0;
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use (&$ticks, $change): void {
            if (1 === ++$ticks) {
                clearstatcache();
                $change();
            }
            if (self::MAX_TICKS === $ticks) {
                $event->getWorker()->stop();
            }
        });

        new Worker([], new MessageBus(), $dispatcher)->run(['sleep' => 1000]);

        return $ticks;
    }
}
