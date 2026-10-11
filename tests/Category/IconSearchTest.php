<?php

namespace App\Tests\Category;

use App\Category\IconSearch;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IconSearchTest extends KernelTestCase
{
    public function testSearchesTheLucideIconsOnce(): void
    {
        $client = new MockHttpClient([
            new JsonMockResponse(['icons' => ['lucide:car', 'lucide:car-front'], 'total' => 2]),
        ]);
        self::getContainer()->set('iconify.client', $client);
        $icons = self::getContainer()->get(IconSearch::class);

        self::assertSame(['lucide:car', 'lucide:car-front'], $icons->search('  Car '));
        // Cached, whatever the case and the spaces
        self::assertSame(['lucide:car', 'lucide:car-front'], $icons->search('car'));
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testBuildsTheQueryOfIconify(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://api.iconify.design/search?query=piggy%20bank&prefix=lucide&limit=64', $url);

            return new JsonMockResponse(['icons' => ['lucide:piggy-bank', 'mdi:piggy-bank']]);
        });
        self::getContainer()->set('iconify.client', $client);

        // Lucide only, whatever comes back
        self::assertSame(['lucide:piggy-bank'], self::getContainer()->get(IconSearch::class)->search('piggy bank'));
    }

    public function testSuggestsIconsWithoutAQuery(): void
    {
        $client = new MockHttpClient([]);
        self::getContainer()->set('iconify.client', $client);

        self::assertSame(IconSearch::SUGGESTIONS, self::getContainer()->get(IconSearch::class)->search(' '));
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testTellsWhenIconifyCantBeReached(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 503]),
            new JsonMockResponse(['icons' => ['lucide:house']]),
        ]);
        self::getContainer()->set('iconify.client', $client);
        $icons = self::getContainer()->get(IconSearch::class);

        self::assertNull($icons->search('house'));
        // Not cached: the next search tries again
        self::assertSame(['lucide:house'], $icons->search('house'));
    }
}
