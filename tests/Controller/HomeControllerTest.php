<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testIntroducesTheApplicationAndLeadsToTheLoginPage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'See where your money goes');
        self::assertSelectorCount(3, '[data-controller="theme"] button[aria-pressed]');

        $client->clickLink('Log in');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Sign in');
    }

    public function testSendsALoggedInUserToTheDashboard(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM '.User::class)->execute();
        $user = new User()->setLogin('alice')->setPassword('not-a-real-hash');
        $entityManager->persist($user);
        $entityManager->flush();

        $client->loginUser($user);
        $client->request('GET', '/');

        self::assertResponseRedirects('/dashboard');
    }

    public function testLayoutIsMobileReady(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
        self::assertStringContainsString('width=device-width', (string) $crawler->filter('meta[name="viewport"]')->attr('content'));
    }
}
