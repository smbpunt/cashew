<?php

namespace App\Tests\Controller;

use App\Entity\BankAccount;
use App\Entity\Category;
use App\Entity\RecurringSeries;
use App\Entity\StatementImport;
use App\Entity\User;
use App\Enum\Bank;
use App\Enum\Periodicity;
use App\Enum\SeriesStatus;
use App\Enum\TransactionKind;
use App\Tests\EntityFactoryTrait;
use App\Twig\Components\CategoryForm;
use App\Twig\Components\PendingSeries;
use App\Twig\Components\SeriesSuggestion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class ReviewControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use EntityFactoryTrait;
    use InteractsWithLiveComponents;

    private KernelBrowser $client;
    private User $user;
    private BankAccount $account;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::mockTime('2026-10-11');

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();

        $this->user = $this->createUser('alice');
        $this->account = $this->createAccount($this->user);
        $this->entityManager->flush();
        $this->client->loginUser($this->user);
    }

    public function testRequiresALoggedInUser(): void
    {
        $this->client->restart();
        $this->client->request('GET', '/review');

        self::assertResponseRedirects('/login');
    }

    public function testSumsUpTheImportsThenListsTheSuggestionsTheSurestFirst(): void
    {
        $import = new StatementImport($this->user, 'history.csv', null, str_repeat('0', 64), new \DateTimeImmutable());
        $import->complete(Bank::CreditAgricole, 26, 2, 0, new \DateTimeImmutable());
        $notMine = new StatementImport($this->createUser('bob'), 'not-mine.csv', null, str_repeat('1', 64), new \DateTimeImmutable());
        $this->entityManager->persist($import);
        $this->entityManager->persist($notMine);
        $this->createSeries('Free Mobile', 83, ['2026-08-15' => -1999, '2026-09-15' => -1999]);
        $this->createSeries('Salaire Acme Sas', 93, ['2026-09-28' => 250000]);
        $this->createSeries('Netflix', 90, [])->reject();
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', \sprintf('/review?imports[]=%d&imports[]=%d', $import->getId(), $notMine->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Review your imports');
        self::assertSelectorCount(1, '[data-import-status]');
        self::assertSelectorTextContains('[data-import-status="done"]', '26 imported, 2 already known');
        self::assertSelectorTextContains('[data-live-name-value="PendingSeries"] h2', '2 recurring transactions to review');
        self::assertSame(['Salaire Acme Sas', 'Free Mobile'], $crawler->filter('[data-series] p.font-medium')->each(static fn ($name): string => $name->text()));
        self::assertSelectorTextContains('[data-series]:last-child', '83% sure');
        self::assertSelectorTextContains('[data-series]:last-child', '2 occurrences, the last one on Sep 15, 2026');
        self::assertSelectorTextContains('[data-nav-review]', '2');
        // No categories yet: a first set is offered
        self::assertSelectorExists('form[action="/categories/seed"] input[name="redirect"][value="app_review"]');
    }

    public function testShowsTheFirstAndLastDaysOfWhatEachImportBrought(): void
    {
        $july = new StatementImport($this->user, 'july.csv', null, str_repeat('0', 64), new \DateTimeImmutable());
        $july->complete(Bank::CreditAgricole, 2, 0, 0, new \DateTimeImmutable());
        $august = new StatementImport($this->user, 'august.csv', null, str_repeat('1', 64), new \DateTimeImmutable());
        $august->complete(Bank::CreditAgricole, 1, 0, 0, new \DateTimeImmutable());
        $known = new StatementImport($this->user, 'known.csv', null, str_repeat('2', 64), new \DateTimeImmutable());
        $known->complete(Bank::CreditAgricole, 0, 3, 0, new \DateTimeImmutable());
        foreach ([$july, $august, $known] as $import) {
            $this->entityManager->persist($import);
        }
        $this->addImported($july, '2026-07-31', -1500);
        $this->addImported($july, '2026-07-02', 250000);
        // A deferred card settlement only adds up purchases imported one by one
        $this->addImported($july, '2026-08-05', -12000, TransactionKind::DeferredCardSettlement);
        $this->addImported($august, '2026-08-14', -4200);
        $this->entityManager->flush();

        $this->client->request('GET', \sprintf('/review?imports[]=%d&imports[]=%d&imports[]=%d', $july->getId(), $august->getId(), $known->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('[data-imports-period]', 'Transactions from Jul 2, 2026 to Aug 14, 2026');
        self::assertSelectorTextSame('[data-import-status]:nth-child(3) [data-import-period]', 'From Jul 2, 2026 to Jul 31, 2026');
        self::assertSelectorTextSame('[data-import-status]:nth-child(2) [data-import-period]', 'On Aug 14, 2026');
        // Nothing new: no days to show
        self::assertSelectorNotExists('[data-import-status]:nth-child(1) [data-import-period]');
    }

    public function testNothingToReview(): void
    {
        $this->client->request('GET', '/review');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-empty]');
        self::assertSelectorNotExists('[data-import-status]');
    }

    public function testConfirmsASuggestionNamedAndCategorised(): void
    {
        $series = $this->createSeries('Edf Clients Particuliers', 93, ['2026-09-05' => -8500]);
        $energy = $this->createCategory($this->user, 'Energy', $this->createCategory($this->user, 'Home'));
        $this->entityManager->flush();

        $component = $this->createLiveComponent(SeriesSuggestion::class, ['series' => $series], $this->client)->actingAs($this->user);
        $rendered = $component->render();
        self::assertSame(['No category', 'Home', 'Home › Energy'], $rendered->crawler()->filter('select option')->each(static fn ($option): string => $option->text()));

        $component->submitForm([\sprintf('series_%d', $series->getId()) => ['name' => 'EDF', 'category' => (string) $energy->getId()]], 'confirm');

        self::assertNotNull($component->getEmittedEvent($component->render(), 'series:reviewed'));
        $series = $this->reload($series);
        self::assertSame(SeriesStatus::Confirmed, $series->getStatus());
        self::assertSame('EDF', $series->getName());
        self::assertSame('Home › Energy', $series->getCategory()?->getPath());
    }

    public function testACategoryCreatedOnThePageKeepsWhatWasTyped(): void
    {
        $series = $this->createSeries('Edf Clients Particuliers', 93, []);
        $this->entityManager->flush();
        $form = \sprintf('series_%d', $series->getId());

        $component = $this->createLiveComponent(SeriesSuggestion::class, ['series' => $series], $this->client)->actingAs($this->user);
        $component->set($form.'.name', 'EDF');
        // Created on the page, through the current entity manager: the kernel rebooted with the request
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->createCategory($this->entityManager->find(User::class, $this->user->getId()) ?? throw new \LogicException('No user.'), 'Energy');
        $this->entityManager->flush();
        $rendered = $component->emit('category:created')->render();

        self::assertSame('EDF', $rendered->crawler()->filter(\sprintf('input[name="%s[name]"]', $form))->attr('value'));
        self::assertSame(['No category', 'Energy'], $rendered->crawler()->filter('select option')->each(static fn ($option): string => $option->text()));
    }

    public function testANameIsNeeded(): void
    {
        $series = $this->createSeries('Free Mobile', 90, []);
        $this->entityManager->flush();

        $component = $this->createLiveComponent(SeriesSuggestion::class, ['series' => $series], $this->client)->actingAs($this->user);

        try {
            $component->submitForm([\sprintf('series_%d', $series->getId()) => ['name' => ' ', 'category' => '']], 'confirm');
            self::fail('The form is invalid.');
        } catch (UnprocessableEntityHttpException) {
        }

        self::assertSame(SeriesStatus::Suggested, $this->reload($series)->getStatus());
    }

    public function testRefusesTheCategoryOfSomeoneElse(): void
    {
        $series = $this->createSeries('Free Mobile', 90, []);
        $theirs = $this->createCategory($this->createUser('bob'), 'Theirs');
        $this->entityManager->flush();

        $component = $this->createLiveComponent(SeriesSuggestion::class, ['series' => $series], $this->client)->actingAs($this->user);

        try {
            $component->submitForm([\sprintf('series_%d', $series->getId()) => ['name' => 'Free', 'category' => (string) $theirs->getId()]], 'confirm');
            self::fail('Not one of the choices.');
        } catch (UnprocessableEntityHttpException) {
        }

        self::assertNull($this->reload($series)->getCategory());
    }

    public function testRejectsASuggestion(): void
    {
        $series = $this->createSeries('Carrefour Marseille', 61, []);
        $this->entityManager->flush();

        $this->createLiveComponent(SeriesSuggestion::class, ['series' => $series], $this->client)->actingAs($this->user)->call('reject');

        self::assertSame(SeriesStatus::Rejected, $this->reload($series)->getStatus());
        // Gone from the list
        $rendered = $this->createLiveComponent(PendingSeries::class, client: $this->client)->actingAs($this->user)->render();
        self::assertCount(0, $rendered->crawler()->filter('[data-series]'));
    }

    public function testCannotReviewTheSeriesOfSomeoneElse(): void
    {
        $theirAccount = $this->createAccount($this->createUser('bob'), Bank::Revolut, 'Personal', 'Personal');
        $series = new RecurringSeries($theirAccount, 'netflix', false, 'Netflix', Periodicity::Monthly, -1349, 90, new \DateTimeImmutable('2026-09-20'));
        $this->entityManager->persist($series);
        $this->entityManager->flush();

        $this->expectException(AccessDeniedException::class);
        $this->createLiveComponent(SeriesSuggestion::class, ['series' => $series], $this->client)->actingAs($this->user)->call('reject');
    }

    public function testCreatesACategoryOnTheWay(): void
    {
        $home = $this->createCategory($this->user, 'Home');
        $this->entityManager->flush();

        $component = $this->createLiveComponent(CategoryForm::class, client: $this->client)->actingAs($this->user);
        $component->submitForm(['category' => ['name' => 'Water', 'parent' => (string) $home->getId()]], 'save');

        self::assertNotNull($component->getEmittedEvent($component->render(), 'category:created'));
        $water = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Category::class)->findOneBy(['name' => 'Water']);
        self::assertSame('Home › Water', $water?->getPath());

        // Twice the same name, at the same place
        try {
            $component->submitForm(['category' => ['name' => 'Water', 'parent' => (string) $home->getId()]], 'save');
            self::fail('The name is taken.');
        } catch (UnprocessableEntityHttpException) {
        }
        self::assertSame(1, self::getContainer()->get(EntityManagerInterface::class)->getRepository(Category::class)->count(['name' => 'Water']));
    }

    public function testShowsWhatChangedInTheConfirmedSeries(): void
    {
        $import = new StatementImport($this->user, 'october.csv', null, str_repeat('0', 64), new \DateTimeImmutable());
        $import->complete(Bank::CreditAgricole, 1, 0, 0, new \DateTimeImmutable());
        $this->entityManager->persist($import);
        $series = $this->createSeries('Free Mobile', 90, ['2026-08-15' => -1999, '2026-09-15' => -1999]);
        $series->confirm('Free', null);
        $this->addTransaction($series, '2026-10-15', -2499, $import);
        $this->entityManager->flush();

        $this->client->request('GET', \sprintf('/review?imports[]=%d', $import->getId()));

        self::assertSelectorTextContains('h2#amount-changes', 'An amount changed');
        self::assertSelectorTextSame('[data-amount-change]', 'Free: €24.99 on Oct 15, 2026, instead of €19.99 on Sep 15, 2026.');
    }

    /**
     * @param array<string, int> $amounts by day
     */
    private function createSeries(string $name, int $confidence, array $amounts): RecurringSeries
    {
        $series = new RecurringSeries($this->account, strtolower($name), array_sum($amounts) > 0, $name, Periodicity::Monthly, (int) (array_first($amounts) ?? -1000), $confidence, new \DateTimeImmutable((string) (array_key_last($amounts) ?? '2026-09-01')));
        $this->entityManager->persist($series);
        foreach ($amounts as $date => $amount) {
            $this->addTransaction($series, $date, $amount);
        }

        return $series;
    }

    private function addTransaction(RecurringSeries $series, string $date, int $amount, ?StatementImport $import = null): void
    {
        $this->createTransaction($this->account, $date, $series->getName(), $amount, TransactionKind::Regular, $import)->attachTo($series);
    }

    private function addImported(StatementImport $import, string $date, int $amount, TransactionKind $kind = TransactionKind::Regular): void
    {
        $this->createTransaction($this->account, $date, 'Label', $amount, $kind, $import);
    }

    private function reload(RecurringSeries $series): RecurringSeries
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(RecurringSeries::class, $series->getId()) ?? throw new \LogicException('The series is gone.');
    }
}
