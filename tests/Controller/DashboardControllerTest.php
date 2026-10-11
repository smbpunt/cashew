<?php

namespace App\Tests\Controller;

use App\Entity\BankAccount;
use App\Entity\StatementImport;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Bank;
use App\Enum\TransactionKind;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class DashboardControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
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
        $this->client->request('GET', '/dashboard');

        self::assertResponseRedirects('/login');
    }

    public function testLeadsToTheImportWithoutData(): void
    {
        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'No data yet');
        self::assertSelectorNotExists('[data-live-name-value="ImportsInProgress"]');

        $this->client->clickLink('Import statements');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Import statements');
    }

    public function testTellsWhenStatementsOnlyHeldTheMonthInProgress(): void
    {
        $import = new StatementImport($this->user, 'october.csv', null, str_repeat('0', 64), new \DateTimeImmutable('2026-10-11'));
        $import->complete(Bank::Revolut, 0, 0, 3, new \DateTimeImmutable('2026-10-11'));
        $this->entityManager->persist($import);
        $this->entityManager->flush();

        $this->client->request('GET', '/dashboard');

        self::assertSelectorTextContains('h1', 'No data yet');
        self::assertSelectorTextContains('main', 'from November 1, 2026');
    }

    public function testFollowsTheImportsInProgress(): void
    {
        $this->entityManager->persist(new StatementImport($this->user, 'history.csv', 'history.csv', str_repeat('0', 64), new \DateTimeImmutable('2026-10-11')));
        $this->entityManager->flush();

        $this->client->request('GET', '/dashboard');

        self::assertSelectorTextContains('[data-live-name-value="ImportsInProgress"]', 'Importing your statement…');
        self::assertSelectorTextContains('[data-live-name-value="ImportsInProgress"]', 'history.csv');
        self::assertSelectorNotExists('h1');
    }

    public function testShowsTheIncomeAndSpendingOfTheLastMonthOnTheDayOfEachPurchase(): void
    {
        $this->createTransactions();

        $crawler = $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'September 2026');
        self::assertSame(['Income €2,500.00', 'Spending €97.39', 'Balance +€2,402.61'], $crawler->filter('dl > div')->each(
            static fn ($tile): string => (string) preg_replace('/\s+/u', ' ', trim($tile->text())),
        ));

        // The purchases of the deferred debit card, on their day; not the monthly debit that settles them
        self::assertSame([
            '28 Sep Virement en votre faveur · VIR SEPA SALAIRE',
            '25 Sep CARREFOUR MARSEILLE',
            '15 Sep Prélèvement · FREE MOBILE',
            '10 Sep Cinéma',
            '2 Sep BOULANGERIE DU PORT',
        ], $crawler->filter('[data-transaction]')->each(static fn ($row): string => $row->filter('time')->text().' '.$row->filter('p')->first()->text()));
        self::assertSelectorTextContains('[data-transaction]:nth-child(4)', 'Revolut · Leisure');

        self::assertSelectorExists('a[rel="prev"][href="/dashboard?month=2026-08"]');
        self::assertSelectorExists('button[disabled][aria-label="Next month"]');
    }

    public function testShowsAnotherMonth(): void
    {
        $this->createTransactions();

        $crawler = $this->client->request('GET', '/dashboard?month=2026-08');

        self::assertSelectorTextContains('h1', 'August 2026');
        self::assertCount(1, $crawler->filter('[data-transaction]'));
        self::assertSelectorExists('button[disabled][aria-label="Previous month"]');
        self::assertSelectorExists('a[rel="next"][href="/dashboard?month=2026-09"]');
    }

    #[TestWith(['2026-07'])]
    #[TestWith(['2026-10'])]
    #[TestWith(['2026-13'])]
    #[TestWith(['september'])]
    public function testAMonthWithoutTransactionsAroundIsNotFound(string $month): void
    {
        $this->createTransactions();

        $this->client->request('GET', '/dashboard?month='.$month);

        self::assertResponseStatusCodeSame(404);
    }

    public function testSpeaksTheLanguageOfTheUser(): void
    {
        $this->createTransactions();

        $this->client->request('GET', '/locale/fr');
        $this->client->request('GET', '/dashboard');

        self::assertSelectorTextContains('h1', 'Septembre 2026');
        self::assertSelectorTextContains('dl', 'Entrées');
    }

    private function createUser(string $login): User
    {
        $user = new User()->setLogin($login)->setPassword('not-a-real-hash');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createTransactions(): void
    {
        $bank = new BankAccount($this->user, Bank::CreditAgricole, '12345678901', 'Compte de Dépôt', 'EUR');
        $revolut = new BankAccount($this->user, Bank::Revolut, 'Personal (EUR)', 'Personal', 'EUR');
        $someoneElses = new BankAccount($this->createUser('bob'), Bank::Revolut, 'Personal (EUR)', 'Personal', 'EUR');

        foreach ([
            [$bank, '2026-08-31', "Prélèvement carte\nDEBIT MENSUEL CARTE 497010XXXXXX1234", -8000, TransactionKind::Regular, null],
            [$bank, '2026-09-02', 'BOULANGERIE DU PORT', -1240, TransactionKind::DeferredCardPurchase, null],
            [$revolut, '2026-09-10', 'Cinéma', -1500, TransactionKind::Regular, 'Leisure'],
            [$bank, '2026-09-15', "Prélèvement\nFREE MOBILE", -1999, TransactionKind::Regular, null],
            [$bank, '2026-09-25', 'CARREFOUR MARSEILLE', -5000, TransactionKind::DeferredCardPurchase, null],
            [$bank, '2026-09-28', "Virement en votre faveur\nVIR SEPA SALAIRE", 250000, TransactionKind::Regular, null],
            [$bank, '2026-09-30', "Prélèvement carte\nDEBIT MENSUEL CARTE 497010XXXXXX1234", -6240, TransactionKind::DeferredCardSettlement, null],
            [$someoneElses, '2026-09-12', 'Not mine', -99900, TransactionKind::Regular, null],
        ] as $i => [$account, $date, $label, $amount, $kind, $category]) {
            $this->entityManager->persist($account);
            $this->entityManager->persist(new Transaction($account, null, new \DateTimeImmutable($date), $label, $amount, 'EUR', $kind, $category, 'fingerprint-'.$i));
        }
        $this->entityManager->flush();
    }
}
