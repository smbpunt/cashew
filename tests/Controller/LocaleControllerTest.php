<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LocaleControllerTest extends WebTestCase
{
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
}
