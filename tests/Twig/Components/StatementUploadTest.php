<?php

namespace App\Tests\Twig\Components;

use App\Entity\StatementImport;
use App\Entity\User;
use App\Message\ImportStatement;
use App\MessageHandler\ImportStatementHandler;
use App\Twig\Components\StatementUpload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class StatementUploadTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use InteractsWithLiveComponents;

    private const string FIXTURES = __DIR__.'/../../Fixtures/Statement/';

    private User $user;

    protected function setUp(): void
    {
        self::createClient();
        self::mockTime('2026-10-11');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM '.User::class)->execute();

        $this->user = new User()->setLogin('alice')->setPassword('not-a-real-hash');
        $entityManager->persist($this->user);
        $entityManager->flush();
    }

    public function testAsksForTheFullHistoryFirst(): void
    {
        $rendered = (string) $this->component()->render();

        self::assertStringContainsString('full history', $rendered);
        self::assertStringContainsString('September 30th, 2026', $rendered);
        self::assertStringContainsString('dropzone-container', $rendered);
    }

    public function testUploadsStatementsThenFollowsTheirImportsUntilTheyAreDone(): void
    {
        $component = $this->upload($this->component(), $this->file('credit_agricole.csv'), $this->file('revolut.csv'));

        $rendered = $component->render();
        self::assertCount(2, $rendered->crawler()->filter('[data-import-status="pending"]'));
        // Follows the imports: through Mercure, and by polling
        self::assertStringContainsString('data-poll="delay(10000)|refresh"', (string) $rendered);
        self::assertCount(1, $rendered->crawler()->filter('[data-controller="statement-import-updates"]'));
        self::assertMatchesRegularExpression('#/\.well-known/mercure\?match=%2Fstatement-imports%2F\d+&amp;match=%2Fstatement-imports%2F\d+#', (string) $rendered);

        $this->handleQueuedMessages(2);

        $rendered = $component->call('refresh')->render();
        self::assertCount(2, $rendered->crawler()->filter('[data-import-status="done"]'));
        self::assertStringContainsString('7 imported', (string) $rendered);
        self::assertStringContainsString('8 imported', (string) $rendered);
        self::assertStringNotContainsString('data-poll', (string) $rendered);
        self::assertCount(0, $rendered->crawler()->filter('[data-controller="statement-import-updates"]'));
        self::assertCount(2, $component->getEmittedEvents($rendered));
        self::assertNotNull($component->getEmittedEvent($rendered, 'statement:imported'));

        // Announced once only
        $rendered = $component->call('refresh')->render();
        self::assertCount(0, $component->getEmittedEvents($rendered));

        // Then each month's statement
        self::assertStringContainsString('Once a month is over', (string) $this->component()->render());
    }

    public function testAFailedImportShowsWhy(): void
    {
        $component = $this->upload($this->component(), $this->file(contents: "Date,Amount\n2026-09-01,12.00\n"));
        $this->handleQueuedMessages(1);

        $rendered = $component->call('refresh')->render();

        self::assertCount(1, $rendered->crawler()->filter('[data-import-status="failed"]'));
        self::assertStringContainsString('This file isn&#039;t a statement export from a supported bank.', (string) $rendered);
    }

    public function testRefusesAFileAlreadyImported(): void
    {
        $component = $this->upload($this->component(), $this->file('credit_agricole.csv'));
        $rendered = $this->upload($component, $this->file('credit_agricole.csv'))->render();

        self::assertStringContainsString('&quot;credit_agricole.csv&quot; is already imported.', (string) $rendered);
        self::assertCount(1, $rendered->crawler()->filter('[data-import-status]'));
    }

    public function testRefusesWhatIsNotACsvFile(): void
    {
        $rendered = $this->upload($this->component(), $this->file(contents: '%PDF-1.7', name: 'statement.pdf'))->render();

        self::assertStringContainsString('Only CSV exports are supported.', (string) $rendered);
        self::assertSame(0, self::getContainer()->get(EntityManagerInterface::class)->getRepository(StatementImport::class)->count([]));
    }

    public function testRefusesAnEmptyUpload(): void
    {
        $rendered = $this->component()->call('upload')->render();

        self::assertStringContainsString('Pick at least one file.', (string) $rendered);
    }

    public function testRequiresALoggedInUser(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->createLiveComponent(StatementUpload::class)->render();
    }

    private function component(): TestLiveComponent
    {
        return $this->createLiveComponent(StatementUpload::class)->actingAs($this->user);
    }

    private function upload(TestLiveComponent $component, UploadedFile ...$files): TestLiveComponent
    {
        // Nested under the form's name, as a browser sends them: call() documents a flat array, but passes them on as they are
        return $component->call('upload', files: ['statement_upload' => ['statements' => array_values($files)]]); // @phpstan-ignore argument.type
    }

    private function file(?string $fixture = null, ?string $contents = null, ?string $name = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'statement');
        file_put_contents($path, $contents ?? file_get_contents(self::FIXTURES.$fixture));

        return new UploadedFile($path, $name ?? $fixture ?? 'statement.csv', test: true);
    }

    private function handleQueuedMessages(int $expected): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        self::assertCount($expected, $transport->getSent());

        $handler = self::getContainer()->get(ImportStatementHandler::class);
        \assert($handler instanceof ImportStatementHandler);
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            \assert($message instanceof ImportStatement);
            $handler($message);
        }
    }
}
