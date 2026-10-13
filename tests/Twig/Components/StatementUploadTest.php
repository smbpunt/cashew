<?php

namespace App\Tests\Twig\Components;

use App\Entity\StatementImport;
use App\Entity\User;
use App\Message\ImportStatement;
use App\MessageHandler\ImportStatementHandler;
use App\Tests\SwitchesLocaleTrait;
use App\Twig\Components\StatementUpload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
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
    use SwitchesLocaleTrait;

    private const string FIXTURES = __DIR__.'/../../Fixtures/Statement/';

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
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
        self::assertStringContainsString('September 30, 2026', $rendered);
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

        // All done: says so, then leaves for the dashboard
        self::assertStringContainsString('Import complete', (string) $rendered);
        self::assertCount(1, $rendered->crawler()->filter('[data-controller="import-complete"][data-import-complete-url-value="/dashboard"]'));
        self::assertCount(0, $rendered->crawler()->filter('form'));

        // Announced once only
        $rendered = $component->call('refresh')->render();
        self::assertCount(0, $component->getEmittedEvents($rendered));

        $this->client->request('GET', '/dashboard');
        self::assertSelectorCount(1, 'main > [data-slot="alert"]');
        self::assertSelectorTextContains('main > [data-slot="alert"]', '15 transactions imported.');

        // Then each month's statement
        self::assertStringContainsString('Once a month is over', (string) $this->component()->render());
    }

    public function testLeavesForTheReviewOfTheRecurringTransactionsFound(): void
    {
        $component = $this->upload($this->component(), $this->file('credit_agricole_recurring.csv'));
        $this->handleQueuedMessages(1);

        $rendered = $component->call('refresh')->render();

        $id = self::getContainer()->get(EntityManagerInterface::class)->getRepository(StatementImport::class)->findOneBy([])?->getId();
        self::assertCount(1, $rendered->crawler()->filter(\sprintf('[data-controller="import-complete"][data-import-complete-url-value="/review?imports%%5B0%%5D=%d"]', $id)));
        self::assertStringContainsString('Taking you to the recurring transactions found…', (string) $rendered);
    }

    public function testAFailedImportShowsWhy(): void
    {
        $component = $this->upload($this->component(), $this->file(contents: "Date,Amount\n2026-09-01,12.00\n"));
        $this->handleQueuedMessages(1);

        $rendered = $component->call('refresh')->render();

        self::assertCount(1, $rendered->crawler()->filter('[data-import-status="failed"]'));
        self::assertStringContainsString('This file isn&#039;t a statement export from a supported bank.', (string) $rendered);
        // Stays for the user to read why, and to upload another file
        self::assertCount(0, $rendered->crawler()->filter('[data-controller="import-complete"]'));
        self::assertCount(1, $rendered->crawler()->filter('form'));
        self::assertCount(1, $rendered->crawler()->filter('a[href="/dashboard"]'));
    }

    public function testFollowsTheImportsStillInProgressWhenOpenedAgain(): void
    {
        $this->upload($this->component(), $this->file('credit_agricole.csv'));

        $rendered = $this->component()->render();

        self::assertCount(1, $rendered->crawler()->filter('[data-import-status="pending"]'));
        self::assertStringContainsString('data-poll="delay(10000)|refresh"', (string) $rendered);
        self::assertStringContainsString('You can leave this page', (string) $rendered);

        // Over: no longer followed (through the current entity manager, the kernel rebooted with the requests)
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($entityManager->getRepository(StatementImport::class)->findAll() as $import) {
            $import->fail('statement.error.unexpected', [], new \DateTimeImmutable());
        }
        $entityManager->flush();
        self::assertCount(0, $this->component()->render()->crawler()->filter('[data-import-status]'));
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

    public function testSpeaksTheLanguageOfTheUser(): void
    {
        $this->switchLocale($this->client, 'fr');

        self::assertStringContainsString('30 septembre 2026', (string) $this->component()->render());

        $component = $this->upload($this->component(), $this->file('credit_agricole.csv'), $this->file(contents: "Date,Amount\n2026-09-01,12.00\n"));
        $this->handleQueuedMessages(2);
        $rendered = (string) $component->call('refresh')->render();
        self::assertStringContainsString('7 importées', $rendered);
        self::assertStringContainsString('Ce fichier n’est pas un export de relevé d’une banque prise en charge.', $rendered);

        $rendered = (string) $this->upload($component, $this->file('credit_agricole.csv'))->render();
        self::assertStringContainsString('« credit_agricole.csv » est déjà importé.', $rendered);

        $rendered = (string) $this->component()->call('upload')->render();
        self::assertStringContainsString('Choisissez au moins un fichier.', $rendered);
    }

    public function testRequiresALoggedInUser(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->createLiveComponent(StatementUpload::class)->render();
    }

    private function component(): TestLiveComponent
    {
        // The same browser for every component, with its session
        return $this->createLiveComponent(StatementUpload::class, client: $this->client)->actingAs($this->user);
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
