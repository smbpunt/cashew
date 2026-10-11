<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SecurityControllerTest extends WebTestCase
{
    private const string PASSWORD = 'Correct-Horse-Battery-9';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Login throttling state lives in this pool and would otherwise leak between tests and runs
        self::getContainer()->get('cache.rate_limiter')->clear();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM '.User::class)->execute();

        $user = new User()->setLogin('alice');
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $entityManager->persist($user);
        $entityManager->flush();
    }

    public function testLoginPageIsReachable(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Sign in');
    }

    public function testLoginSucceedsWhateverTheCaseOfTheLogin(): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => 'ALICE', '_password' => self::PASSWORD]);

        self::assertResponseRedirects('/statements/import');

        $this->client->request('GET', '/login');
        self::assertSelectorTextContains('[data-slot="alert"]', 'You are logged in as alice');
    }

    public function testLoginFailsWithAWrongPassword(): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => 'alice', '_password' => 'wrong-password']);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Invalid credentials.');
    }

    public function testLoginFailsWithAnUnknownLoginWithTheSameMessage(): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => 'nobody', '_password' => self::PASSWORD]);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Invalid credentials.');
    }

    public function testLoginIsThrottledAfterFiveFailuresWhateverTheCaseOrSpacesOfTheLogin(): void
    {
        foreach (['alice', 'Alice', ' alice', 'ALICE ', ' aLiCe '] as $login) {
            $this->client->request('GET', '/login');
            $this->client->submitForm('Sign in', ['_username' => $login, '_password' => 'wrong-password']);
        }

        // Even the right password is refused until the window is over
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => 'ALICE', '_password' => self::PASSWORD]);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Too many failed login attempts');
    }

    public function testLogoutThroughTheLogoutLink(): void
    {
        $this->logIn();

        $this->client->request('GET', '/login', server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $this->client->clickLink('log out');
        self::assertResponseRedirects('/');

        $this->client->request('GET', '/login');
        self::assertSelectorNotExists('[data-slot="alert"]');
    }

    public function testLogoutIsRefusedWhenRequestedFromAnotherSite(): void
    {
        $this->logIn();
        $logoutUrl = $this->client->request('GET', '/login')->selectLink('log out')->link()->getUri();

        $this->client->request('GET', $logoutUrl, server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/login');
        self::assertSelectorTextContains('[data-slot="alert"]', 'You are logged in as alice');
    }

    public function testLogoutIsRefusedWithoutToken(): void
    {
        $this->logIn();

        $this->client->request('GET', '/logout', server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseStatusCodeSame(403);
    }

    private function logIn(): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => 'alice', '_password' => self::PASSWORD]);
        self::assertResponseRedirects('/statements/import');
    }
}
