<?php

namespace App\Tests\Twig\Components;

use App\Entity\StatementImport;
use App\Entity\User;
use App\Enum\Bank;
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
    use InteractsWithLiveComponents;

    private EntityManagerInterface $entityManager;
    private User $user;

    protected function setUp(): void
    {
        self::createClient();
        self::mockTime('2026-10-11');

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();

        $this->user = $this->createUser('alice');
    }

    public function testFollowsTheImportsOfTheUserUntilTheyAreOverThenGoesBackToTheDashboard(): void
    {
        $history = $this->createImport($this->user, 'history.csv');
        $revolut = $this->createImport($this->user, 'revolut.csv');
        $revolut->start();
        $this->createImport($this->createUser('bob'), 'not-mine.csv');
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

    public function testRequiresALoggedInUser(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->createLiveComponent(ImportsInProgress::class)->render();
    }

    private function component(): TestLiveComponent
    {
        return $this->createLiveComponent(ImportsInProgress::class)->actingAs($this->user);
    }

    private function createUser(string $login): User
    {
        $user = new User()->setLogin($login)->setPassword('not-a-real-hash');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createImport(User $owner, string $filename): StatementImport
    {
        $import = new StatementImport($owner, $filename, $filename, hash('sha256', $filename), new \DateTimeImmutable());
        $this->entityManager->persist($import);

        return $import;
    }
}
