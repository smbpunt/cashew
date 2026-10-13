<?php

namespace App\Tests\Controller;

use App\Entity\RecurringSeries;
use App\Entity\User;
use App\Enum\Bank;
use App\Enum\Periodicity;
use App\Enum\SeriesStatus;
use App\Enum\TransactionKind;
use App\Tests\EntityFactoryTrait;
use App\Tests\SwitchesLocaleTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class CalendarControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use EntityFactoryTrait;
    use SwitchesLocaleTrait;

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::mockTime('2026-10-11');

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();

        $this->user = $this->createUser('alice');
        $this->client->loginUser($this->user);
    }

    public function testRequiresALoggedInUser(): void
    {
        $this->client->restart();
        $this->client->request('GET', '/calendar');

        self::assertResponseRedirects('/login');
    }

    public function testLeadsToTheImportWithoutData(): void
    {
        $this->client->request('GET', '/calendar');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'No data yet');
        self::assertSelectorExists('header nav a[href="/calendar"][aria-current="page"]');
    }

    public function testExpectsTheRecurringSpendingOfTheMonthInProgress(): void
    {
        $this->createTransactions();

        $this->client->request('GET', '/calendar');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'October 2026');
        self::assertSelectorExists('header nav a[href="/calendar"][aria-current="page"]');
        // Neither the suggestion, nor the salary, nor the magazine that stopped coming
        self::assertSame(['Rent', 'Netflix'], $this->entries());
        self::assertSelectorCount(2, '[data-entries] [data-expected="true"]');
        self::assertSelectorExists('[data-day="2026-10-01"] [data-entry="Rent"][data-expected="true"]');
        self::assertSelectorExists('[data-day="2026-10-05"] [data-entry="Netflix"]');
        self::assertSelectorTextContains('[data-currency="EUR"] [data-total]', '€763.49');
        self::assertSelectorTextContains('[data-currency="EUR"] [data-expected-total]', 'including €763.49 expected');

        self::assertSelectorTextContains('[data-to-check]', 'A subscription to check');
        self::assertSelectorTextContains('[data-to-check] [data-series="Magazine"]', 'Last seen on July 10, 2026');
        self::assertSelectorNotExists('[data-ended]');
    }

    public function testExpectsThemInTheMonthsAheadToo(): void
    {
        $this->createTransactions();

        $this->client->request('GET', '/calendar?month=2026-11');

        self::assertSelectorTextContains('h1', 'November 2026');
        self::assertSame(['Rent', 'Netflix'], $this->entries());
        self::assertSelectorExists('[data-day="2026-11-05"] [data-entry="Netflix"]');
        self::assertSelectorExists('[data-to-check]');
    }

    public function testShowsTheRecurringSpendingOfAPastMonthOrAllOfIt(): void
    {
        $this->createTransactions();

        $this->client->request('GET', '/calendar?month=2026-09');

        self::assertSame(['Rent', 'Netflix'], $this->entries());
        self::assertSelectorNotExists('[data-entries] [data-expected="true"]');
        self::assertSelectorNotExists('[data-expected-total]');
        self::assertSelectorNotExists('[data-to-check]');
        self::assertSelectorExists('[data-filter="recurring"][aria-current="true"]');

        $this->client->clickLink('All spending');

        self::assertSelectorExists('[data-filter="all"][aria-current="true"]');
        // Not the money moved to another of the accounts, nor the salary
        self::assertSame(['Rent', 'Netflix', 'SUPERMARKET', 'GYM'], $this->entries());
        self::assertSelectorTextContains('[data-currency="EUR"] [data-total]', '€892.18');
    }

    public function testTheMonthLinksKeepTheFilter(): void
    {
        $this->createTransactions();

        $this->client->request('GET', '/calendar?month=2026-09&show=all');

        self::assertSelectorExists('a[rel="prev"][href="/calendar?month=2026-08&show=all"]');
        self::assertSelectorExists('a[rel="next"][href="/calendar?month=2026-10&show=all"]');
    }

    public function testGoesAYearAhead(): void
    {
        $this->createTransactions();

        $this->client->request('GET', '/calendar?month=2027-10');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('button[aria-label="Next month"][disabled]');
    }

    #[TestWith(['2026-06'])]
    #[TestWith(['2027-11'])]
    #[TestWith(['2026-13'])]
    #[TestWith(['september'])]
    public function testAMonthOutOfReachIsNotFound(string $month): void
    {
        $this->createTransactions();

        $this->client->request('GET', '/calendar?month='.$month);

        self::assertResponseStatusCodeSame(404);
    }

    public function testASubscriptionMarkedAsEndedIsNoLongerExpected(): void
    {
        $this->createTransactions();

        $crawler = $this->client->request('GET', '/calendar?month=2026-11');
        $this->client->submit($crawler->filter('[data-entries] [data-entry="Netflix"] form button')->form());

        self::assertResponseRedirects('/calendar?month=2026-11&show=recurring', 303);
        $crawler = $this->client->followRedirect();
        self::assertSame(['Rent'], $this->entries());
        self::assertSelectorTextContains('[data-ended] [data-series="Netflix"]', 'Ended on October 11, 2026');

        $this->client->submit($crawler->filter('[data-ended] [data-series="Netflix"] form button')->form());
        $this->client->followRedirect();
        self::assertSame(['Rent', 'Netflix'], $this->entries());
        self::assertSelectorNotExists('[data-ended]');
    }

    public function testASubscriptionThatSeemsEndedIsToTell(): void
    {
        $this->createTransactions();

        $crawler = $this->client->request('GET', '/calendar');
        $this->client->submit($crawler->filter('[data-to-check] [data-series="Magazine"] [data-series-action="keep"]')->form());
        $crawler = $this->client->followRedirect();

        // Expected again, until another one goes missing
        self::assertSelectorNotExists('[data-to-check]');
        self::assertSame(['Rent', 'Netflix', 'Magazine'], $this->entries());
        self::assertSelectorExists('[data-day="2026-10-10"] [data-entry="Magazine"][data-expected="true"]');

        $this->client->submit($crawler->filter('[data-entries] [data-entry="Magazine"] form button')->form());
        $this->client->followRedirect();
        self::assertSame(['Rent', 'Netflix'], $this->entries());
        self::assertSelectorExists('[data-ended] [data-series="Magazine"]');
    }

    public function testTheSubscriptionsOfSomeoneElseAreOutOfReach(): void
    {
        $theirs = $this->createSeries($this->createAccount($this->createUser('bob'), externalId: '456'), 'Their gym', -2999, '2026-09-15');
        $this->entityManager->flush();

        $this->client->request('POST', \sprintf('/calendar/series/%d/end', $theirs->getId()), ['_token' => 'whatever']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->find($theirs)->isEnded());
    }

    public function testAnInvalidTokenEndsNothing(): void
    {
        $this->createTransactions();
        $netflix = $this->entityManager->getRepository(RecurringSeries::class)->findOneBy(['counterparty' => $this->createCounterparty($this->user, 'netflix')]);
        self::assertNotNull($netflix);

        $this->client->request('POST', \sprintf('/calendar/series/%d/end', $netflix->getId()), ['_token' => 'whatever']);

        // An invalid token fails the authentication of the request
        self::assertResponseRedirects('/login');
        self::assertFalse($this->find($netflix)->isEnded());
    }

    public function testSpeaksTheLanguageOfTheUser(): void
    {
        $this->createTransactions();
        $this->switchLocale($this->client, 'fr');

        $this->client->request('GET', '/calendar');

        self::assertSelectorTextContains('h1', 'Octobre 2026');
        self::assertSelectorTextContains('header nav', 'Calendrier');
        self::assertSelectorTextContains('[data-to-check]', 'Un abonnement à vérifier');
        self::assertSelectorTextContains('[data-entries] [data-entry="Netflix"]', 'prévu');
    }

    /**
     * Alice's July to September, and a subscription of Bob's.
     */
    private function createTransactions(): void
    {
        $account = $this->createAccount($this->user);
        $streaming = $this->createCategory($this->user, 'Streaming');

        $netflix = $this->createSeries($account, 'Netflix', -1349, '2026-09-05', category: $streaming);
        foreach (['08', '09'] as $month) {
            $this->createTransaction($account, "2026-$month-05", "Prélèvement\nNETFLIX", -1349)->attachTo($netflix);
        }
        $rent = $this->createSeries($account, 'Rent', -75000, '2026-09-01');
        foreach (['08', '09'] as $month) {
            $this->createTransaction($account, "2026-$month-01", "Virement\nVIR SEPA LOYER", -75000)->attachTo($rent);
        }
        $magazine = $this->createSeries($account, 'Magazine', -890, '2026-07-10');
        $this->createTransaction($account, '2026-07-10', "Prélèvement\nMAGAZINE", -890)->attachTo($magazine);
        $salary = $this->createSeries($account, 'Salary', 250000, '2026-09-28');
        $this->createTransaction($account, '2026-09-28', "Virement en votre faveur\nACME", 250000)->attachTo($salary);
        $gym = $this->createSeries($account, 'Gym', -2999, '2026-09-15', status: SeriesStatus::Suggested);
        $this->createTransaction($account, '2026-09-15', 'GYM', -2999)->attachTo($gym);

        $this->createTransaction($account, '2026-09-12', 'SUPERMARKET', -9870);
        $this->createTransaction($account, '2026-09-20', "Virement\nVIR SEPA VERS LIVRET", -10000, TransactionKind::InternalTransfer);

        $bobs = $this->createAccount($this->createUser('bob'), Bank::Revolut, '456');
        $this->createSeries($bobs, 'Their gym', -2999, '2026-09-15', Periodicity::Weekly);

        $this->entityManager->flush();
    }

    /**
     * The names of the entries listed, in order.
     *
     * @return list<string>
     */
    private function entries(): array
    {
        return $this->client->getCrawler()->filter('[data-entries] [data-entry]')->each(static fn ($node): string => (string) $node->attr('data-entry'));
    }

    private function find(RecurringSeries $series): RecurringSeries
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(RecurringSeries::class, $series->getId()) ?? throw new \LogicException('No such series.');
    }
}
