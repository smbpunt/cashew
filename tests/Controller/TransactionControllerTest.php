<?php

namespace App\Tests\Controller;

use App\Entity\RecurringSeries;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Bank;
use App\Enum\CategoryRole;
use App\Enum\Periodicity;
use App\Tests\EntityFactoryTrait;
use App\Twig\Components\TransactionCategory;
use App\Twig\Components\TransactionName;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class TransactionControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use EntityFactoryTrait;
    use InteractsWithLiveComponents;

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::mockTime('2026-10-11');

        $this->resetDatabase();

        $this->user = $this->createUser('alice');
        $this->client->loginUser($this->user);
    }

    public function testRequiresALoggedInUser(): void
    {
        $transaction = $this->createTransaction($this->createAccount($this->user), '2026-09-12', 'CARREFOUR', -5000);
        $this->entityManager->flush();

        $this->client->restart();
        $this->client->request('GET', \sprintf('/transactions/%d', $transaction->getId()));

        self::assertResponseRedirects('/login');
    }

    public function testShowsATransactionAmongTheOthersOfItsSeries(): void
    {
        $energy = $this->createCategory($this->user, 'Energy');
        $account = $this->createAccount($this->user);
        $edf = new RecurringSeries($account, $this->createCounterparty($this->user, 'edf'), false, Periodicity::Monthly, -8500, 93, new \DateTimeImmutable('2026-09-05'));
        $edf->confirm('Electricity', $energy);
        $this->entityManager->persist($edf);
        $occurrences = [];
        foreach (['2026-07-05' => -8500, '2026-08-05' => -8500, '2026-09-05' => -9200] as $date => $amount) {
            $occurrences[$date] = $this->createTransaction($account, $date, "Prélèvement\nEDF", $amount);
            $occurrences[$date]->attachTo($edf);
        }
        // Not one of the series: left out, though from the same counterparty
        $this->createTransaction($account, '2026-08-20', "Prélèvement\nEDF", -30000);
        // The last month with transactions
        $this->createTransaction($account, '2026-10-02', 'CARREFOUR', -5000);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', \sprintf('/transactions/%d', $occurrences['2026-08-05']->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Electricity');
        self::assertSelectorTextSame('[data-recurring]', 'Recurring · Monthly');
        self::assertSelectorExists('a[data-back][href="/dashboard?month=2026-08"]');
        self::assertSame([
            'total' => 'Total -€262.00 in 3 transactions',
            'average' => 'Average per transaction -€87.33 -€87.33 a month',
            'period' => 'Period Since Jul 5, 2026 Over 3 months, up to Sep 5, 2026',
            'range' => 'Amounts From -€85.00 to -€92.00',
        ], self::stats($crawler));
        // Up to the last month with transactions, that of the transaction highlighted
        self::assertSame(['2026-07' => '-8500', '2026-08' => '-8500', '2026-09' => '-9200', '2026-10' => '0'], self::bars($crawler));
        self::assertSelectorExists('[data-month="2026-08"][aria-current="true"]');
        self::assertSame(['Sep 5, 2026', 'Aug 5, 2026', 'Jul 5, 2026'], $crawler->filter('[data-occurrence] time')->each(static fn (Crawler $time): string => $time->text()));
        self::assertSelectorTextContains('[data-occurrence][aria-current="true"]', 'Aug 5, 2026');
    }

    public function testShowsATransactionAmongTheOthersOfItsCounterpartyOnEveryAccount(): void
    {
        $bank = $this->createAccount($this->user);
        $revolut = $this->createAccount($this->user, Bank::Revolut, 'Personal (EUR)', 'Personal');
        $carrefour = $this->createTransaction($bank, '2026-09-12', 'CB CARREFOUR 12/09', -5000);
        $this->createTransaction($revolut, '2026-08-03', 'Carrefour', -2000);
        $this->createTransaction($bank, '2026-07-03', 'CB CARREFOUR 03/07', -3000);
        // A refund, another shop, someone else's purchase: left out
        $this->createTransaction($bank, '2026-09-20', 'CARREFOUR', 1500);
        $this->createTransaction($bank, '2026-09-14', 'CB LIDL 14/09', -1000);
        $this->createTransaction($this->createAccount($this->createUser('bob')), '2026-09-12', 'CB CARREFOUR 12/09', -99900);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', \sprintf('/transactions/%d', $carrefour->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'CB CARREFOUR 12/09');
        self::assertSelectorNotExists('[data-recurring]');
        self::assertSame('Total -€100.00 in 3 transactions', self::stats($crawler)['total']);
        self::assertSame(['2026-07' => '-3000', '2026-08' => '-2000', '2026-09' => '-5000'], self::bars($crawler));
        self::assertSame(
            ['Sep 12, 2026 CB CARREFOUR 12/09 Compte de Dépôt -€50.00', 'Aug 3, 2026 Carrefour Personal -€20.00', 'Jul 3, 2026 CB CARREFOUR 03/07 Compte de Dépôt -€30.00'],
            $crawler->filter('[data-occurrence]')->each(static fn (Crawler $row): string => (string) preg_replace('/\s+/u', ' ', trim($row->text()))),
        );

        // Each occurrence leads to its own page
        $this->client->click($crawler->filter('[data-occurrence]')->eq(1)->filter('a')->link());
        self::assertSelectorTextSame('h1', 'Carrefour');
        self::assertSelectorTextContains('[data-occurrence][aria-current="true"]', 'Aug 3, 2026');
    }

    public function testATransactionOfItsOwnHasNoHistory(): void
    {
        $lidl = $this->createTransaction($this->createAccount($this->user), '2026-09-14', 'CB LIDL 14/09', -1000);
        $this->entityManager->flush();

        $this->client->request('GET', \sprintf('/transactions/%d', $lidl->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('[data-only-once]', 'The only transaction with this counterparty so far.');
        self::assertSelectorNotExists('[data-stat]');
        self::assertSelectorNotExists('[data-occurrence]');
    }

    public function testTheTransactionsOfSomeoneElseAreOutOfReach(): void
    {
        $transaction = $this->createTransaction($this->createAccount($this->createUser('bob')), '2026-09-12', 'CARREFOUR', -5000);
        $this->entityManager->flush();

        $this->client->request('GET', \sprintf('/transactions/%d', $transaction->getId()));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', \sprintf('/transactions/%d', $transaction->getId() + 1));
        self::assertResponseStatusCodeSame(404);
    }

    public function testNamesAllTheTransactionsOfItsCounterparty(): void
    {
        $bank = $this->createAccount($this->user);
        $revolut = $this->createAccount($this->user, Bank::Revolut, 'Personal (EUR)', 'Personal');
        $carrefour = $this->createTransaction($bank, '2026-09-12', 'CB CARREFOUR 12/09', -5000);
        $other = $this->createTransaction($revolut, '2026-08-03', 'Carrefour', -2000);
        $theirs = $this->createTransaction($this->createAccount($this->createUser('bob')), '2026-09-12', 'CB CARREFOUR 12/09', -99900);
        $this->entityManager->flush();

        $component = $this->createLiveComponent(TransactionName::class, ['transaction' => $carrefour], $this->client)->actingAs($this->user);
        $component->call('edit');
        $form = $component->render()->crawler();
        // A name made from its counterparty to start from
        self::assertSame('Carrefour', $form->filter('input[name="rename[name]"]')->attr('value'));
        self::assertSame('For the 2 transactions of the counterparty that aren’t recurring, and those to come. Blank, they go back to their label.', trim($form->filter('[data-rename-scope]')->text()));

        $component->submitForm(['rename' => ['name' => 'Groceries']], 'rename');
        self::assertSame('Groceries', $component->render()->crawler()->filter('h1')->text());

        $this->client->request('GET', \sprintf('/transactions/%d', $other->getId()));
        self::assertSelectorTextSame('h1', 'Groceries');
        self::assertSelectorTextContains('[data-transaction-name]', 'Carrefour');
        // Someone else's counterparty is theirs
        $this->client->loginUser($theirs->getAccount()->getOwner());
        $this->client->request('GET', \sprintf('/transactions/%d', $theirs->getId()));
        self::assertSelectorTextSame('h1', 'CB CARREFOUR 12/09');

        // A blank name takes it back
        $component->submitForm(['rename' => ['name' => ' ']], 'rename');
        self::assertSame('CB CARREFOUR 12/09', $component->render()->crawler()->filter('h1')->text());
    }

    public function testNamesTheSeriesOfATransactionOfOne(): void
    {
        $edf = $this->createSeries($this->createAccount($this->user), 'EDF', -8500, '2026-09-05');
        $occurrence = $this->createTransaction($edf->getAccount(), '2026-09-05', "Prélèvement\nEDF", -8500);
        $occurrence->attachTo($edf);
        // From the same counterparty, in no series
        $repair = $this->createTransaction($edf->getAccount(), '2026-08-20', "Prélèvement\nEDF", -30000);
        $this->entityManager->flush();

        $component = $this->createLiveComponent(TransactionName::class, ['transaction' => $occurrence], $this->client)->actingAs($this->user);
        $component->call('edit');
        self::assertSame('For this transaction of the series, and those to come. Blank, the series takes the name of the counterparty.', trim($component->render()->crawler()->filter('[data-rename-scope]')->text()));
        $component->submitForm(['rename' => ['name' => 'Electricity']], 'rename');

        self::assertSame('Electricity', self::getContainer()->get(EntityManagerInterface::class)->find(RecurringSeries::class, $edf->getId())?->getName());
        $this->client->request('GET', \sprintf('/transactions/%d', $repair->getId()));
        self::assertSelectorTextSame('h1', 'Prélèvement');
    }

    public function testATransactionOfNoCounterpartyHasNoNameToChange(): void
    {
        $transaction = $this->createTransaction($this->createAccount($this->user), '2026-09-12', '12345678 0987654', -5000);
        $this->entityManager->flush();

        $this->client->request('GET', \sprintf('/transactions/%d', $transaction->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-transaction-name] [data-live-action-param="edit"]');
    }

    public function testFilesAllTheTransactionsOfItsCounterpartyFromItsPage(): void
    {
        $account = $this->createAccount($this->user);
        $rent = $this->createCategory($this->user, 'Rent');
        $leisure = $this->createCategory($this->user, 'Leisure');
        $transfers = $this->createCategory($this->user, 'Transfers')->setRole(CategoryRole::Transfers);
        $september = $this->createTransaction($account, '2026-09-01', "Virement émis\nWEB Loyer", -80000);
        $september->categorize($transfers);
        // Filed as a transfer on import, of its own
        $august = $this->createTransaction($account, '2026-08-01', "Virement émis\nWEB Loyer", -80000);
        $august->categorize($transfers);
        $july = $this->createTransaction($account, '2026-07-01', "Virement émis\nWEB Loyer", -80000);
        $july->categorize($leisure);
        $this->entityManager->flush();

        $this->client->request('GET', \sprintf('/transactions/%d', $september->getId()));
        self::assertSelectorTextSame('[data-category-scope]', 'for all the transactions of the counterparty');

        $this->createLiveComponent(TransactionCategory::class, ['transaction' => $september, 'ofCounterparty' => true], $this->client)
            ->actingAs($this->user)
            ->set('categoryId', $rent->getId());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('Rent', $entityManager->find(Transaction::class, $september->getId())?->getCounterparty()?->getCategory()?->getName());
        // The transfers give way, not the category the user gave one of its own
        self::assertSame(['Rent', 'Rent', 'Leisure'], array_map(
            static fn (Transaction $transaction): ?string => $entityManager->find(Transaction::class, $transaction->getId())?->getEffectiveCategory()?->getName(),
            [$september, $august, $july],
        ));

        $this->client->request('GET', \sprintf('/transactions/%d', $august->getId()));
        self::assertSelectorExists('[data-transaction-name] [data-category-icon]');
    }

    public function testClearsTheCategoryOfItsCounterparty(): void
    {
        $leisure = $this->createCategory($this->user, 'Leisure');
        $cinema = $this->createTransaction($this->createAccount($this->user), '2026-09-10', 'Cinéma', -1500);
        $cinema->getCounterparty()?->categorize($leisure);
        $this->entityManager->flush();

        $this->createLiveComponent(TransactionCategory::class, ['transaction' => $cinema, 'ofCounterparty' => true], $this->client)
            ->actingAs($this->user)
            ->set('categoryId', null);

        $cleared = self::getContainer()->get(EntityManagerInterface::class)->find(Transaction::class, $cinema->getId());
        self::assertNull($cleared?->getCounterparty()?->getCategory());
        self::assertNull($cleared?->getEffectiveCategory());
    }

    public function testMoneyMovedBetweenTheAccountsOfTheUserIsCategorisedOnItsOwn(): void
    {
        $savings = $this->createCategory($this->user, 'Savings');
        $transfer = $this->createTransaction($this->createAccount($this->user), '2026-09-10', 'To Bastien Nicolau', -20000);
        $transfer->markAsInternalTransfer();
        $this->entityManager->flush();

        $this->client->request('GET', \sprintf('/transactions/%d', $transfer->getId()));
        self::assertSelectorNotExists('[data-category-scope]');

        $this->createLiveComponent(TransactionCategory::class, ['transaction' => $transfer, 'ofCounterparty' => true], $this->client)
            ->actingAs($this->user)
            ->set('categoryId', $savings->getId());

        $categorised = self::getContainer()->get(EntityManagerInterface::class)->find(Transaction::class, $transfer->getId());
        self::assertSame('Savings', $categorised?->getCategory()?->getName());
        self::assertNull($categorised?->getCounterparty()?->getCategory());
    }

    public function testCannotCategoriseTheCounterpartyOfSomeoneElse(): void
    {
        $leisure = $this->createCategory($this->user, 'Leisure');
        $theirs = $this->createTransaction($this->createAccount($this->createUser('bob')), '2026-09-10', 'Cinéma', -1500);
        $this->entityManager->flush();

        $this->expectException(AccessDeniedException::class);
        $this->createLiveComponent(TransactionCategory::class, ['transaction' => $theirs, 'ofCounterparty' => true], $this->client)
            ->actingAs($this->user)
            ->set('categoryId', $leisure->getId());
    }

    public function testCannotNameTheTransactionOfSomeoneElse(): void
    {
        $transaction = $this->createTransaction($this->createAccount($this->createUser('bob')), '2026-09-12', 'CARREFOUR', -5000);
        $this->entityManager->flush();

        $this->expectException(AccessDeniedException::class);
        $this->createLiveComponent(TransactionName::class, ['transaction' => $transaction], $this->client)->actingAs($this->user)
            ->submitForm(['rename' => ['name' => 'Mine now']], 'rename');
    }

    /**
     * @return array<string, string> by stat
     */
    private static function stats(Crawler $crawler): array
    {
        return array_combine(
            $crawler->filter('[data-stat]')->each(static fn (Crawler $tile): string => (string) $tile->attr('data-stat')),
            $crawler->filter('[data-stat]')->each(static fn (Crawler $tile): string => (string) preg_replace('/\s+/u', ' ', trim($tile->text()))),
        );
    }

    /**
     * @return array<string, string> by month
     */
    private static function bars(Crawler $crawler): array
    {
        return array_combine(
            $crawler->filter('[data-month]')->each(static fn (Crawler $bar): string => (string) $bar->attr('data-month')),
            $crawler->filter('[data-month]')->each(static fn (Crawler $bar): string => (string) $bar->attr('data-amount')),
        );
    }
}
