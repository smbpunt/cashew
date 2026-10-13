<?php

namespace App\Tests\Controller;

use App\Entity\RecurringSeries;
use App\Entity\User;
use App\Enum\Bank;
use App\Enum\Periodicity;
use App\Tests\EntityFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;

final class TransactionControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use EntityFactoryTrait;

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
        $edf = new RecurringSeries($account, 'edf', false, 'EDF', Periodicity::Monthly, -8500, 93, new \DateTimeImmutable('2026-09-05'));
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
