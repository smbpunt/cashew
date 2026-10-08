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
}
