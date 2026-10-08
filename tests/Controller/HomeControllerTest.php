<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testHomepageSaysHelloWorld(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Hello, world!');
        self::assertSelectorCount(8, '[data-greeting-target="option"]');
        self::assertSelectorCount(1, '[data-greeting-target="option"][aria-pressed="true"]');
    }

    public function testLayoutIsMobileReady(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
        self::assertStringContainsString('width=device-width', (string) $crawler->filter('meta[name="viewport"]')->attr('content'));
    }
}
