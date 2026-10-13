<?php

namespace App\Tests\Controller;

use App\Category\CategorySeeder;
use App\Entity\Category;
use App\Entity\User;
use App\Tests\EntityFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LocaleControllerTest extends WebTestCase
{
    use EntityFactoryTrait;

    public function testTheInterfaceIsInEnglishByDefault(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertSame('en', $crawler->filter('html')->attr('lang'));
        self::assertSelectorTextContains('h1', 'Sign in');
        self::assertSelectorExists('nav a[hreflang="en"][aria-current="true"]');
    }

    public function testSwitchesToFrenchThenBackToEnglish(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');

        $client->clickLink('fr');
        self::assertResponseRedirects('/login');

        $crawler = $client->followRedirect();
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        self::assertSelectorTextContains('h1', 'Connexion');
        self::assertSelectorExists('nav a[hreflang="fr"][aria-current="true"]');

        $client->clickLink('en');
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Sign in');
    }

    public function testAnotherLocaleIsNotFound(): void
    {
        $client = self::createClient();
        $client->request('GET', '/locale/de');

        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('foreignTargets')]
    public function testNeverRedirectsToAnotherSite(string $target): void
    {
        $client = self::createClient();
        $client->request('GET', '/locale/fr', ['target' => $target]);

        self::assertResponseRedirects('/');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignTargets(): iterable
    {
        yield 'absolute URL' => ['https://evil.example/login'];
        yield 'protocol-relative URL' => ['//evil.example/login'];
        yield 'backslash' => ['/\evil.example/login'];
        yield 'relative path' => ['login'];
    }

    public function testNamesTheDefaultCategoriesTheUserKeptInTheNewLanguage(): void
    {
        [$client, $user] = $this->logInWithDefaultCategories();
        $energy = $this->entityManager->getRepository(Category::class)->findOneBy(['owner' => $user, 'name' => 'Energy']);
        $energy?->setName('Power');
        // Named as a default one, but the user's
        $this->createCategory($user, 'Savings', $this->entityManager->getRepository(Category::class)->findOneBy(['owner' => $user, 'name' => 'Banking']));
        $this->entityManager->flush();

        $client->request('GET', '/locale/fr', ['target' => '/review']);
        self::assertResponseRedirects('/review');
        $client->followRedirect();
        self::assertSelectorExists('select option:contains("Maison")');

        $paths = $this->categoryPaths($user);
        self::assertContains('Maison › Loyer', $paths);
        self::assertContains('Maison › Power', $paths);
        self::assertContains('Banque › Savings', $paths);
        self::assertContains('Épargne', $paths);
        self::assertContains('Virements', $paths);
        self::assertNotContains('Home', $paths);

        $client->request('GET', '/locale/en');
        $paths = $this->categoryPaths($user);
        self::assertContains('Home › Rent', $paths);
        self::assertContains('Home › Power', $paths);
        self::assertContains('Transfers', $paths);
    }

    public function testLeavesADefaultCategoryWhoseNameIsTakenInTheNewLanguage(): void
    {
        [$client, $user] = $this->logInWithDefaultCategories();
        $this->createCategory($user, 'Maison');
        $this->entityManager->flush();

        $client->request('GET', '/locale/fr');

        $paths = $this->categoryPaths($user);
        self::assertContains('Home › Loyer', $paths);
        self::assertContains('Maison', $paths);
    }

    /**
     * @return array{KernelBrowser, User}
     */
    private function logInWithDefaultCategories(): array
    {
        $client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();

        $user = $this->createUser('alice');
        self::getContainer()->get(CategorySeeder::class)->seed($user);
        $client->loginUser($user);

        return [$client, $user];
    }

    /**
     * @return list<string>
     */
    private function categoryPaths(User $user): array
    {
        // The kernel rebooted with the request: its entity manager, not the one the categories were created with
        return array_map(
            static fn (Category $category): string => $category->getPath(),
            self::getContainer()->get(EntityManagerInterface::class)->getRepository(Category::class)->findBy(['owner' => $user->getId()]),
        );
    }
}
