<?php

namespace App\Tests\Twig\Components;

use App\Entity\RecurringSeries;
use App\Entity\StatementImport;
use App\Entity\User;
use App\Enum\Bank;
use App\Enum\Periodicity;
use App\Tests\EntityFactoryTrait;
use App\Twig\Components\ImportsInProgress;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class ImportsInProgressTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use EntityFactoryTrait;
    use InteractsWithLiveComponents;

    private User $user;

    protected function setUp(): void
    {
        self::createClient();
        self::mockTime('2026-10-11');

        $this->resetDatabase();

        $this->user = $this->createUser('alice');
    }

    public function testFollowsTheImportsOfTheUserUntilTheyAreOverThenGoesBackToTheDashboard(): void
    {
        $history = $this->createImport($this->user, 'history.csv', 'history.csv');
        $revolut = $this->createImport($this->user, 'revolut.csv', 'revolut.csv');
        $revolut->start();
        $this->createImport($this->createUser('bob'), 'not-mine.csv', 'not-mine.csv');
        $this->entityManager->flush();

        $component = $this->component();
        $rendered = $component->render();

        self::assertStringContainsString('Importing your 2 statements…', (string) $rendered);
        self::assertStringNotContainsString('not-mine.csv', (string) $rendered);
        self::assertStringContainsString('data-poll="delay(10000)|refresh"', (string) $rendered);
        self::assertMatchesRegularExpression('#/\.well-known/mercure\?match=%2Fstatement-imports%2F\d+&amp;match=%2Fstatement-imports%2F\d+#', (string) $rendered);

        // One is over, the other still running
        $history->fail('statement.error.unsupported_bank', [], new \DateTimeImmutable());
        $this->entityManager->flush();
        $component->call('refresh');
        self::assertTrue($component->response()->isSuccessful());
        self::assertStringContainsString('Importing your statement…', (string) $component->render());

        // The kernel rebooted with the requests: through its current entity manager
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->find(StatementImport::class, $revolut->getId())?->complete(Bank::Revolut, 3, 0, 0, new \DateTimeImmutable());
        $entityManager->flush();
        $component->call('refresh');
        self::assertTrue($component->response()->isRedirect('/dashboard'));
    }

    public function testGoesToTheReviewOfTheRecurringTransactionsFoundByTheImportsItFollowed(): void
    {
        $history = $this->createImport($this->user, 'history.csv', 'history.csv');
        $this->entityManager->persist(new RecurringSeries($this->createAccount($this->user), $this->createCounterparty($this->user, 'free mobile'), false, Periodicity::Monthly, -1999, 90, new \DateTimeImmutable('2026-09-15')));
        $this->entityManager->flush();

        $component = $this->component();
        self::assertStringContainsString('history.csv', (string) $component->render());

        // The kernel rebooted with the request: through its current entity manager
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->find(StatementImport::class, $history->getId())?->complete(Bank::CreditAgricole, 26, 0, 0, new \DateTimeImmutable());
        $entityManager->flush();
        $component->call('refresh');

        // For it to sum up the import
        self::assertTrue($component->response()->isRedirect(\sprintf('/review?imports%%5B0%%5D=%d', $history->getId())));
    }

    public function testRequiresALoggedInUser(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->createLiveComponent(ImportsInProgress::class)->render();
    }

    private function component(): TestLiveComponent
    {
        return $this->createLiveComponent(ImportsInProgress::class)->actingAs($this->user);
    }
}
