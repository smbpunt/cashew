<?php

namespace App\Tests\EventListener;

use App\Entity\Category;
use App\Entity\User;
use App\Tests\EntityFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SeedCategoriesOnLoginTest extends WebTestCase
{
    use EntityFactoryTrait;

    private const string PASSWORD = 'Correct-Horse-Battery-9';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Login throttling state lives in this pool and would otherwise leak between tests and runs
        self::getContainer()->get('cache.rate_limiter')->clear();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();

        $this->user = new User()->setLogin('alice');
        $this->user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->user, self::PASSWORD));
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    public function testGivesTheDefaultCategoriesAtTheFirstLoginInTheLanguageOfTheLoginPage(): void
    {
        $this->client->request('GET', '/locale/fr');
        $this->logIn('Se connecter');

        self::assertContains('Maison › Loyer', $this->categoryPaths());
        self::assertContains('Revenus › Salaire', $this->categoryPaths());
        self::assertContains('Épargne', $this->categoryPaths());
        self::assertTrue($this->reloadUser()->hasSeededCategories());
    }

    public function testDoesNotBringThemBackOnceDeleted(): void
    {
        $this->logIn('Sign in');
        self::assertNotEmpty($this->categoryPaths());

        self::getContainer()->get(EntityManagerInterface::class)->createQuery('DELETE FROM '.Category::class)->execute();
        // A new session, as a logout would leave
        $this->client->restart();
        $this->logIn('Sign in');

        // But the system one, which the import files transfers in
        self::assertSame(['Transfers'], $this->categoryPaths());
    }

    public function testLeavesTheCategoriesOfAUserWhoMadeTheirOwnAlready(): void
    {
        $this->createCategory($this->user, 'Mine');
        $this->entityManager->flush();

        $this->logIn('Sign in');

        self::assertSame(['Mine', 'Transfers'], $this->categoryPaths());
        self::assertTrue($this->reloadUser()->hasSeededCategories());
    }

    private function logIn(string $button): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm($button, ['_username' => 'alice', '_password' => self::PASSWORD]);
        self::assertResponseRedirects('/dashboard');
    }

    private function reloadUser(): User
    {
        // The kernel rebooted with the requests: its entity manager, not the one of setUp()
        return self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $this->user->getId()) ?? throw new \LogicException('No user.');
    }

    /**
     * @return list<string>
     */
    private function categoryPaths(): array
    {
        return array_map(
            static fn (Category $category): string => $category->getPath(),
            self::getContainer()->get(EntityManagerInterface::class)->getRepository(Category::class)->findBy(['owner' => $this->user->getId()]),
        );
    }
}
