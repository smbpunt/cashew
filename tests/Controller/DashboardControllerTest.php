<?php

namespace App\Tests\Controller;

use App\Entity\RecurringSeries;
use App\Entity\StatementImport;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Bank;
use App\Enum\CategoryRole;
use App\Enum\Periodicity;
use App\Enum\TransactionKind;
use App\Tests\EntityFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class DashboardControllerTest extends WebTestCase
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
        $this->client->restart();
        $this->client->request('GET', '/dashboard');

        self::assertResponseRedirects('/login');
    }

    public function testLeadsToTheImportWithoutData(): void
    {
        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'No data yet');
        self::assertSelectorCount(3, 'header [data-controller="theme"] button[aria-pressed]');
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
        self::assertSame(['Income €2,500.00', 'Spending €97.39', 'Balance +€2,402.61'], $crawler->filter('dl[data-currency] > div')->each(
            static fn ($tile): string => (string) preg_replace('/\s+/u', ' ', trim($tile->text())),
        ));

        // The purchases of the deferred debit card, on their day; not the monthly debit that settles them
        self::assertSame([
            '28 Sep Virement en votre faveur · VIR SEPA SALAIRE',
            '25 Sep CARREFOUR MARSEILLE',
            '15 Sep Prélèvement · FREE MOBILE',
            '10 Sep Cinéma',
            '2 Sep BOULANGERIE DU PORT',
        ], $crawler->filter('[data-transaction]')->each(static fn ($row): string => $row->filter('time')->text().' '.$row->filter('a')->first()->text()));

        self::assertSelectorExists('a[rel="prev"][href="/dashboard?month=2026-08"]');
        self::assertSelectorExists('button[disabled][aria-label="Next month"]');
    }

    public function testListsTheTransactionsSimplyByDefault(): void
    {
        $this->createTransactions();

        $this->client->request('GET', '/dashboard');

        self::assertSelectorExists('[data-mode="simple"][aria-current="true"]');
        self::assertSelectorExists('a[data-mode="detailed"][aria-current="false"][href="/dashboard?month=2026-09&mode=detailed"]');
        self::assertSelectorTextSame('[data-transaction]:nth-child(4)', '10 Sep Cinéma -€15.00');
    }

    public function testListsTheDetailsOfTheTransactionsAndRemembersIt(): void
    {
        $this->createTransactions();

        $crawler = $this->client->request('GET', '/dashboard?month=2026-09&mode=detailed');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-mode="detailed"][aria-current="true"]');
        self::assertSame(
            ['Account Personal', 'Bank Revolut', 'Bank category Leisure', 'Category None'],
            $crawler->filter('[data-transaction]:nth-child(4) [data-detail]')->each(static fn ($detail): string => $detail->text()),
        );
        // The whole label, one line per line of the statement
        self::assertSame(['Prélèvement', 'FREE MOBILE'], $crawler->filter('[data-transaction]:nth-child(3) p')->each(static fn ($line): string => $line->text()));
        self::assertSelectorTextContains('[data-transaction]:nth-child(3)', 'Compte de Dépôt');
        self::assertSelectorTextContains('[data-transaction]:nth-child(3)', 'Crédit Agricole');
        self::assertSelectorNotExists('[data-transaction]:nth-child(3) [data-detail="bank_category"]');
        self::assertSelectorTextContains('[data-transaction]:nth-child(2)', 'Deferred debit card');

        $this->client->click($crawler->filter('a[rel="prev"]')->link());
        self::assertSelectorTextContains('h1', 'August 2026');
        self::assertSelectorExists('[data-mode="detailed"][aria-current="true"]');
        self::assertSelectorTextContains('[data-transaction]', 'Compte de Dépôt');

        $this->client->clickLink('Simple');
        self::assertSelectorExists('[data-mode="simple"][aria-current="true"]');
        self::assertSelectorNotExists('[data-detail]');

        $this->client->request('GET', '/dashboard');
        self::assertSelectorExists('[data-mode="simple"][aria-current="true"]');
    }

    #[TestWith(['simple'])]
    #[TestWith(['detailed'])]
    public function testNamesTheTransactionsOfAConfirmedSeriesAfterIt(string $mode): void
    {
        $this->createTransactions();

        $transactions = $this->entityManager->getRepository(Transaction::class);
        $free = $transactions->findOneBy(['label' => "Prélèvement\nFREE MOBILE"]) ?? throw new \LogicException('No FREE MOBILE.');
        $series = new RecurringSeries($free->getAccount(), 'free mobile', false, 'free mobile', Periodicity::Monthly, -1999, 90, $free->getDate());
        $series->confirm('Phone plan', null);
        $this->entityManager->persist($series);
        $free->attachTo($series);
        // Only suggested: not named after it yet
        $cinema = $transactions->findOneBy(['label' => 'Cinéma']) ?? throw new \LogicException('No Cinéma.');
        $suggested = new RecurringSeries($cinema->getAccount(), 'cinema', false, 'Movies', Periodicity::Monthly, -1500, 60, $cinema->getDate());
        $this->entityManager->persist($suggested);
        $cinema->attachTo($suggested);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/dashboard?month=2026-09&mode='.$mode);

        // Leading to the transaction, out of the frame of the list
        self::assertSelectorTextSame(\sprintf('[data-transaction]:nth-child(3) a[href="/transactions/%d"][data-turbo-frame="_top"]', $free->getId()), 'Phone plan');
        self::assertSelectorTextSame(\sprintf('[data-transaction]:nth-child(4) a[href="/transactions/%d"][data-turbo-frame="_top"]', $cinema->getId()), 'Cinéma');
        if ('simple' === $mode) {
            // The label of the statement, on hover
            self::assertSelectorExists('[data-transaction]:nth-child(3) a[title="Prélèvement · FREE MOBILE"]');
        } else {
            // Over the whole label, and marked as recurring
            self::assertSame(['Phone plan', 'Prélèvement', 'FREE MOBILE'], $crawler->filter('[data-transaction]:nth-child(3) p')->each(static fn ($line): string => $line->text()));
            self::assertSelectorTextSame('[data-transaction]:nth-child(3) [data-recurring]', 'Recurring');
            self::assertSelectorNotExists('[data-transaction]:nth-child(4) [data-recurring]');
        }
    }

    public function testBreaksTheMonthDownByCategory(): void
    {
        $this->createTransactions();
        $home = $this->createCategory($this->user, 'Home');
        $internet = $this->createCategory($this->user, 'Internet', $home);
        $leisure = $this->createCategory($this->user, 'Leisure');

        $transactions = $this->entityManager->getRepository(Transaction::class);
        $free = $transactions->findOneBy(['label' => "Prélèvement\nFREE MOBILE"]) ?? throw new \LogicException('No FREE MOBILE.');
        // The series' category, for its transactions
        $series = new RecurringSeries($free->getAccount(), 'free mobile', false, 'Free', Periodicity::Monthly, -1999, 90, $free->getDate());
        $series->confirm('Free', $internet);
        $this->entityManager->persist($series);
        $free->attachTo($series);
        // Its own
        $transactions->findOneBy(['label' => 'Cinéma'])?->categorize($leisure);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/dashboard?month=2026-09');

        self::assertSame(
            ['Home -€19.99 Internet -€19.99', 'Leisure -€15.00', 'Uncategorized spending -€62.40'],
            $crawler->filter('[data-categories="EUR"] > ul > li')->each(static fn ($row): string => (string) preg_replace('/\s+/u', ' ', trim($row->text()))),
        );
        self::assertSelectorTextContains('[data-fixed-charges]', '€19.99');
        self::assertSelectorExists(\sprintf('[data-categories] a[href="/categories/%d?month=2026-09"]', $home->getId()));
    }

    public function testMoneyMovedBetweenTheAccountsOfTheUserIsListedButNeitherEarnedNorSpent(): void
    {
        $this->createTransactions();
        $transfers = $this->createCategory($this->user, 'Transfers')->setRole(CategoryRole::Transfers);
        $transactions = $this->entityManager->getRepository(Transaction::class);
        $bank = $transactions->findOneBy(['label' => "Prélèvement\nFREE MOBILE"])?->getAccount() ?? throw new \LogicException('No account.');
        $revolut = $transactions->findOneBy(['label' => 'Cinéma'])?->getAccount() ?? throw new \LogicException('No account.');
        foreach ([[$bank, "Virement émis\nVIR INST vers Revolut", -20000], [$revolut, 'Paiement envoyé par ALICE', 20000]] as [$account, $label, $amount]) {
            $side = $this->createTransaction($account, '2026-09-20', $label, $amount);
            $side->markAsInternalTransfer();
            $side->categorize($transfers);
        }
        // To someone else: spent
        $this->createTransaction($revolut, '2026-09-21', 'To BAPTISTE C', -3500)->categorize($transfers);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/dashboard?month=2026-09&mode=detailed');

        self::assertSame(['Income €2,500.00', 'Spending €132.39', 'Balance +€2,367.61'], $crawler->filter('dl[data-currency] > div')->each(
            static fn ($tile): string => (string) preg_replace('/\s+/u', ' ', trim($tile->text())),
        ));
        self::assertSelectorTextContains('[data-categories="EUR"]', 'Transfers -€35.00');
        self::assertSelectorCount(2, '[data-internal-transfer]');
    }

    #[TestWith(['simple'])]
    #[TestWith(['detailed'])]
    public function testShowsTheIconOfTheCategoryOfEachTransaction(string $mode): void
    {
        $this->createTransactions();
        $home = $this->createCategory($this->user, 'Home')->setIcon('lucide:house');
        // Without an icon of its own: that of its parent
        $internet = $this->createCategory($this->user, 'Internet', $home);
        $leisure = $this->createCategory($this->user, 'Leisure')->setIcon('lucide:ticket');

        $transactions = $this->entityManager->getRepository(Transaction::class);
        $free = $transactions->findOneBy(['label' => "Prélèvement\nFREE MOBILE"]) ?? throw new \LogicException('No FREE MOBILE.');
        $series = new RecurringSeries($free->getAccount(), 'free mobile', false, 'Free', Periodicity::Monthly, -1999, 90, $free->getDate());
        $series->confirm('Free', $internet);
        $this->entityManager->persist($series);
        $free->attachTo($series);
        $transactions->findOneBy(['label' => 'Cinéma'])?->categorize($leisure);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/dashboard?month=2026-09&mode='.$mode);

        // Before the label, a question mark without a category
        self::assertSame(
            ['none', 'none', 'lucide:house', 'lucide:ticket', 'none'],
            $crawler->filter('[data-transaction] [data-category-icon]')->each(static fn ($icon): string => (string) $icon->attr('data-category-icon')),
        );
        self::assertSelectorExists('[data-transaction]:nth-child(3) svg[data-category-icon="lucide:house"][aria-hidden="true"]');
        self::assertSelectorExists('[data-categories] [data-category="Leisure"] svg[data-category-icon="lucide:ticket"]');
        self::assertSelectorExists('[data-transaction]:nth-child(1) svg[data-category-icon="none"][role="img"][aria-label="No category"][class~="text-muted-foreground/50"]');
    }

    public function testWithoutCategoriesOnlyShowsTheUncategorizedSpending(): void
    {
        $this->createTransactions();

        $this->client->request('GET', '/dashboard?month=2026-09');

        // Uncategorized spending only
        self::assertSelectorCount(1, '[data-categories] > ul > li');
        self::assertSelectorNotExists('[data-fixed-charges]');
    }

    public function testAnUnknownModeIsNotFound(): void
    {
        $this->createTransactions();

        $this->client->request('GET', '/dashboard?mode=fancy');

        self::assertResponseStatusCodeSame(404);
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

    private function createTransactions(): void
    {
        $bank = $this->createAccount($this->user, Bank::CreditAgricole, '12345678901');
        $revolut = $this->createAccount($this->user, Bank::Revolut, 'Personal (EUR)', 'Personal');
        $someoneElses = $this->createAccount($this->createUser('bob'), Bank::Revolut, 'Personal (EUR)', 'Personal');

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
            $this->entityManager->persist(new Transaction($account, null, new \DateTimeImmutable($date), $label, $amount, 'EUR', $kind, $category, 'fingerprint-'.$i));
        }
        $this->entityManager->flush();
    }
}
