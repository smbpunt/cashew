<?php

namespace App\Tests\Twig\Components;

use App\Category\IconSearch;
use App\Entity\User;
use App\Tests\EntityFactoryTrait;
use App\Twig\Components\IconPicker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class IconPickerTest extends WebTestCase
{
    use EntityFactoryTrait;
    use InteractsWithLiveComponents;

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The same container from one request to the next, for Iconify to stay mocked
        $this->client->disableReboot();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();

        $this->user = $this->createUser('alice');
    }

    public function testSuggestsIconsBeforeAnySearch(): void
    {
        $crawler = $this->component()->render()->crawler();

        self::assertSame(IconSearch::SUGGESTIONS, $crawler->filter('[data-icon-option]')->each(static fn ($option): string => (string) $option->attr('data-icon-option')));
        self::assertSame('Search in English: home, car…', $crawler->filter('input[type="search"]')->attr('placeholder'));
        self::assertCount(1, $crawler->filter('summary [data-icon=""]'));
        self::assertCount(0, $crawler->filter('[data-icon-none]'));
    }

    public function testSearchesTheIcons(): void
    {
        $this->mockIconify(new JsonMockResponse(['icons' => ['lucide:car', 'lucide:car-front']]));

        $crawler = $this->component()->set('query', 'car')->render()->crawler();

        self::assertSame(['lucide:car', 'lucide:car-front'], $crawler->filter('[data-icon-option]')->each(static fn ($option): string => (string) $option->attr('data-icon-option')));
        self::assertSame('car-front', $crawler->filter('[data-icon-option="lucide:car-front"]')->attr('aria-label'));
        // Drawn by the browser, not fetched one by one by the server
        self::assertStringContainsString("url('https://api.iconify.design/lucide/car-front.svg')", (string) $crawler->filter('[data-icon-option="lucide:car-front"] span')->attr('style'));
    }

    public function testTellsWhenNothingIsFound(): void
    {
        $this->mockIconify(new JsonMockResponse(['icons' => []]));

        $crawler = $this->component()->set('query', 'zzz')->render()->crawler();

        self::assertSame('No icon for "zzz".', $crawler->filter('[data-no-results]')->text());
    }

    public function testTellsWhenTheIconsCantBeSearched(): void
    {
        $this->mockIconify(new MockResponse('', ['http_code' => 503]));

        $crawler = $this->component()->set('query', 'car')->render()->crawler();

        self::assertSame("The icons can't be searched right now.", $crawler->filter('[data-unavailable]')->text());
    }

    public function testPicksAnIconForTheFormAroundIt(): void
    {
        $component = $this->component(['name' => 'category[icon]']);
        $component->call('pick', ['icon' => 'lucide:car']);

        $rendered = $component->render();
        self::assertSame(['icon' => 'lucide:car'], $component->getEmittedEvent($rendered, 'icon:picked')['data'] ?? null);
        self::assertSame('lucide:car', $rendered->crawler()->filter('input[type="hidden"][name="category[icon]"]')->attr('value'));
        self::assertCount(1, $rendered->crawler()->filter('summary svg[data-icon="lucide:car"]'));
        self::assertSame('true', $rendered->crawler()->filter('[data-icon-option="lucide:car"]')->attr('aria-pressed'));

        // Or none
        $component->call('pick');
        self::assertSame('', $component->render()->crawler()->filter('input[name="category[icon]"]')->attr('value'));
    }

    public function testOnlyPicksALucideIcon(): void
    {
        $component = $this->component(['value' => 'mdi:home']);
        self::assertNull($component->component()->value);

        $component->call('pick', ['icon' => 'mdi:home']);
        self::assertNull($component->component()->value);
    }

    public function testRequiresALoggedInUser(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->createLiveComponent(IconPicker::class, client: $this->client)->call('pick', ['icon' => 'lucide:car']);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function component(array $props = []): TestLiveComponent
    {
        return $this->createLiveComponent(IconPicker::class, $props, $this->client)->actingAs($this->user);
    }

    private function mockIconify(MockResponse $response): void
    {
        self::getContainer()->set('iconify.client', new MockHttpClient([$response], 'https://api.iconify.design'));
    }
}
