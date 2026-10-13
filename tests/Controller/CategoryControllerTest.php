<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\RecurringSeries;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Bank;
use App\Enum\CategoryRole;
use App\Enum\Periodicity;
use App\Tests\EntityFactoryTrait;
use App\Twig\Components\CategoryForm;
use App\Twig\Components\TransactionCategory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class CategoryControllerTest extends WebTestCase
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

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();

        $this->user = $this->createUser('alice');
        $this->client->loginUser($this->user);
    }

    public function testRequiresALoggedInUser(): void
    {
        $this->client->restart();
        $this->client->request('GET', '/categories');

        self::assertResponseRedirects('/login');
    }

    public function testOffersAFirstSetOfCategoriesInTheLanguageOfTheUser(): void
    {
        $this->client->request('GET', '/locale/fr');
        $this->client->request('GET', '/categories');
        self::assertSelectorExists('[data-empty]');

        $this->client->submitForm('Créer les catégories suggérées');

        self::assertResponseRedirects('/categories');
        $crawler = $this->client->followRedirect();
        self::assertSelectorNotExists('form[action="/categories/seed"]');
        self::assertSame(['Abonnements', 'Achats', 'Alimentation', 'Banque', 'Épargne', 'Impôts', 'Loisirs', 'Maison', 'Revenus', 'Santé', 'Transport', 'Virements'], $crawler->filter('[data-category] > div > a:first-child')->each(static fn ($link): string => $link->text()));
        self::assertSame(['Assurance', 'Eau', 'Énergie', 'Entretien & travaux', 'Internet', 'Loyer', 'Prêt'], $crawler->filter('[data-category="Maison"] li')->each(static fn ($child): string => $child->text()));
        self::assertSelectorExists('[data-category="Maison"] svg[data-category-icon="lucide:house"]');
        self::assertSelectorExists('[data-category="Revenus"] svg[data-category-icon="lucide:wallet"]');
        // Seeded once and for all: deleting them won't bring them back at the next login
        self::assertTrue(self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $this->user->getId())?->hasSeededCategories());
    }

    public function testRenamesAndMovesACategory(): void
    {
        $home = $this->createCategory($this->user, 'Home');
        $water = $this->createCategory($this->user, 'Water bill');
        $this->entityManager->flush();

        $this->client->request('GET', \sprintf('/categories/%d/edit', $water->getId()));
        $this->client->submitForm('Save', ['category[name]' => 'Water', 'category[parent]' => (string) $home->getId()]);

        self::assertResponseRedirects('/categories');
        $this->client->followRedirect();
        self::assertSelectorTextSame('[data-category="Home"] li', 'Water');
    }

    public function testGivesACategoryAnIcon(): void
    {
        $water = $this->createCategory($this->user, 'Water');
        $this->entityManager->flush();

        $this->client->request('GET', \sprintf('/categories/%d/edit', $water->getId()));
        self::assertSelectorExists('summary [data-icon=""]');
        $this->client->submitForm('Save', ['category[icon]' => 'lucide:droplet']);

        self::assertResponseRedirects('/categories');
        $this->client->followRedirect();
        self::assertSelectorExists('[data-category="Water"] svg[data-category-icon="lucide:droplet"]');

        $this->client->request('GET', \sprintf('/categories/%d/edit', $water->getId()));
        self::assertSelectorExists('summary svg[data-icon="lucide:droplet"]');
        self::assertInputValueSame('category[icon]', 'lucide:droplet');

        // And takes it back
        $this->client->submitForm('Save', ['category[icon]' => '']);
        self::assertNull(self::getContainer()->get(EntityManagerInterface::class)->find(Category::class, $water->getId())?->getIcon());
    }

    #[TestWith(['mdi:home'])]
    #[TestWith(['lucide:<script>'])]
    public function testOnlyTakesALucideIcon(string $icon): void
    {
        $water = $this->createCategory($this->user, 'Water');
        $this->entityManager->flush();

        $this->client->request('GET', \sprintf('/categories/%d/edit', $water->getId()));
        $this->client->submitForm('Save', ['category[icon]' => $icon]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form ul', 'Pick one of the icons offered.');
    }

    public function testCreatesACategoryWithTheIconPicked(): void
    {
        $component = $this->createLiveComponent(CategoryForm::class, client: $this->client)->actingAs($this->user);
        $component->emit('icon:picked', ['icon' => 'lucide:zap']);
        $component->submitForm(['category' => ['name' => 'Energy']], 'save');

        $energy = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Category::class)->findOneBy(['name' => 'Energy']);
        self::assertSame('lucide:zap', $energy?->getIcon());
        // Ready for the next one
        self::assertCount(1, $component->render()->crawler()->filter('summary [data-icon=""]'));
    }

    public function testCategoriesHaveTwoLevelsAtMost(): void
    {
        $home = $this->createCategory($this->user, 'Home');
        $energy = $this->createCategory($this->user, 'Energy', $home);
        $water = $this->createCategory($this->user, 'Water');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', \sprintf('/categories/%d/edit', $water->getId()));
        // Only top categories to put it in
        self::assertSame(['None (top category)', 'Home'], $crawler->filter('select option')->each(static fn ($option): string => $option->text()));

        // With subcategories, it stays at the top
        $crawler = $this->client->request('GET', \sprintf('/categories/%d/edit', $home->getId()));
        self::assertSelectorExists('select[disabled][class*="disabled:opacity-50"]');

        // Even when forced
        $this->client->request('POST', \sprintf('/categories/%d/edit', $water->getId()), ['category' => [
            'name' => 'Water',
            'parent' => (string) $energy->getId(),
            '_token' => $crawler->filter('input[name="category[_token]"]')->attr('value'),
        ]]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testDeletesACategoryAndItsSubcategories(): void
    {
        $home = $this->createCategory($this->user, 'Home');
        $this->createCategory($this->user, 'Energy', $home);
        $this->entityManager->flush();

        $this->client->request('GET', \sprintf('/categories/%d/edit', $home->getId()));
        $this->client->submitForm('Delete');

        self::assertResponseRedirects('/categories');
        self::assertSame(0, self::getContainer()->get(EntityManagerInterface::class)->getRepository(Category::class)->count([]));
    }

    public function testDeletingNeedsAValidToken(): void
    {
        $home = $this->createCategory($this->user, 'Home');
        $this->entityManager->flush();

        $this->client->request('POST', \sprintf('/categories/%d/delete', $home->getId()), ['_token' => 'forged']);

        self::assertResponseRedirects('/login');
        self::assertSame(1, self::getContainer()->get(EntityManagerInterface::class)->getRepository(Category::class)->count([]));
    }

    public function testTheTransfersCategoryCanBeRenamedButNotDeleted(): void
    {
        $transfers = $this->createCategory($this->user, 'Transfers')->setRole(CategoryRole::Transfers);
        $this->entityManager->flush();

        $this->client->request('GET', \sprintf('/categories/%d/edit', $transfers->getId()));
        self::assertSelectorNotExists('form[action$="/delete"]');
        self::assertSelectorTextContains('[data-system-category]', 'it can be renamed, not deleted');
        // It stays at the top
        self::assertSelectorNotExists('select[name="category[parent]"]');

        $this->client->submitForm('Save', ['category[name]' => 'Money sent']);
        self::assertResponseRedirects('/categories');

        $this->client->request('POST', \sprintf('/categories/%d/delete', $transfers->getId()), ['_token' => 'whatever']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Money sent', self::getContainer()->get(EntityManagerInterface::class)->find(Category::class, $transfers->getId())?->getName());
    }

    public function testTheCategoriesOfSomeoneElseAreOutOfReach(): void
    {
        $theirs = $this->createCategory($this->createUser('bob'), 'Theirs');
        $this->entityManager->flush();

        $this->client->request('GET', \sprintf('/categories/%d', $theirs->getId()));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', \sprintf('/categories/%d/edit', $theirs->getId()));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/categories');
        self::assertSelectorNotExists('[data-category="Theirs"]');
    }

    public function testShowsACategoryMonthByMonthWithItsSubcategories(): void
    {
        $home = $this->createCategory($this->user, 'Home');
        $energy = $this->createCategory($this->user, 'Energy', $home);
        $loan = $this->createCategory($this->user, 'Loan', $home);
        $this->entityManager->flush();

        $account = $this->createAccount($this->user);
        $edf = new RecurringSeries($account, 'edf', false, 'EDF', Periodicity::Monthly, -8500, 93, new \DateTimeImmutable('2026-09-05'));
        $edf->confirm('EDF', $energy);
        $this->entityManager->persist($edf);
        foreach (['2026-07-05' => -8500, '2026-08-05' => -8500, '2026-09-05' => -9200] as $date => $amount) {
            $this->createTransaction($account, $date, "Prélèvement\nEDF", $amount)->attachTo($edf);
        }
        $this->createTransaction($account, '2026-09-10', "Prélèvement\nECHEANCE PRET", -85000)->categorize($loan);
        $this->createTransaction($account, '2026-08-20', 'LEROY MERLIN', -4250)->categorize($home);
        $this->createTransaction($account, '2026-09-12', 'CARREFOUR', -5000);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', \sprintf('/categories/%d', $home->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Home');
        self::assertSelectorExists('header nav a[href="/categories"][aria-current="page"]');
        self::assertSame(
            ['2026-07' => '-8500', '2026-08' => '-12750', '2026-09' => '-94200'],
            array_combine($crawler->filter('[data-month]')->each(static fn ($bar): string => (string) $bar->attr('data-month')), $crawler->filter('[data-month]')->each(static fn ($bar): string => (string) $bar->attr('data-amount'))),
        );
        self::assertSelectorTextContains('[data-currency="EUR"]', '-€384.83 a month on average, over 3 months');
        self::assertSelectorExists('[data-month="2026-09"][aria-current="true"]');
        self::assertSame(['10 Sep Prélèvement · ECHEANCE PRET Loan -€850.00', '5 Sep EDF Energy -€92.00'], $crawler->filter('[data-transaction]')->each(static fn ($row): string => (string) preg_replace('/\s+/u', ' ', trim($row->text()))));

        // Another month, a subcategory
        $this->client->request('GET', \sprintf('/categories/%d?month=2026-08', $energy->getId()));
        self::assertSelectorExists('[data-month="2026-08"][aria-current="true"]');
        self::assertSelectorTextContains('h1', 'Energy');
        self::assertSelectorCount(1, '[data-transaction]');

        $this->client->request('GET', \sprintf('/categories/%d?month=2026-13', $energy->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testCategorisesATransactionOverItsSeries(): void
    {
        $leisure = $this->createCategory($this->user, 'Leisure');
        $account = $this->createAccount($this->user, Bank::Revolut, 'Personal', 'Personal');
        $transaction = $this->createTransaction($account, '2026-09-10', 'Cinéma', -1500);
        $this->entityManager->flush();

        $component = $this->createLiveComponent(TransactionCategory::class, ['transaction' => $transaction], $this->client)->actingAs($this->user);
        $component->set('categoryId', $leisure->getId());

        self::assertSame('Leisure', self::getContainer()->get(EntityManagerInterface::class)->find(Transaction::class, $transaction->getId())?->getCategory()?->getName());
        self::assertSame((string) $leisure->getId(), $component->render()->crawler()->filter('option[selected]')->attr('value'));

        $component->set('categoryId', null);
        self::assertNull(self::getContainer()->get(EntityManagerInterface::class)->find(Transaction::class, $transaction->getId())?->getCategory());
    }

    public function testCannotCategoriseTheTransactionOfSomeoneElse(): void
    {
        $account = $this->createAccount($this->createUser('bob'), Bank::Revolut, 'Personal', 'Personal');
        $transaction = $this->createTransaction($account, '2026-09-10', 'Not mine', -1500);
        $this->entityManager->flush();

        $this->expectException(AccessDeniedException::class);
        $this->createLiveComponent(TransactionCategory::class, ['transaction' => $transaction], $this->client)->actingAs($this->user)->set('categoryId', null);
    }
}
