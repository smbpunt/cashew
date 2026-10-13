<?php

namespace App\Category;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Finds Lucide icons for a category, through the search of Iconify: in English, "home" or "car".
 */
final readonly class IconSearch
{
    /**
     * What a budget often needs, offered before anything is searched: the icons in assets/icons, rendered offline.
     */
    public const array SUGGESTIONS = [
        'lucide:house', 'lucide:key-round', 'lucide:zap', 'lucide:droplet', 'lucide:wifi', 'lucide:hammer',
        'lucide:shopping-cart', 'lucide:shopping-basket', 'lucide:utensils', 'lucide:coffee', 'lucide:beer',
        'lucide:car', 'lucide:fuel', 'lucide:bus', 'lucide:train-front', 'lucide:bike', 'lucide:square-parking',
        'lucide:smartphone', 'lucide:tv', 'lucide:repeat', 'lucide:heart-pulse', 'lucide:stethoscope', 'lucide:pill',
        'lucide:party-popper', 'lucide:ticket', 'lucide:plane', 'lucide:dumbbell', 'lucide:music', 'lucide:gamepad-2',
        'lucide:book-open', 'lucide:shopping-bag', 'lucide:shirt', 'lucide:laptop', 'lucide:gift', 'lucide:scissors',
        'lucide:baby', 'lucide:dog', 'lucide:graduation-cap', 'lucide:receipt', 'lucide:landmark', 'lucide:coins',
        'lucide:credit-card', 'lucide:piggy-bank', 'lucide:wallet', 'lucide:banknote', 'lucide:hand-heart',
    ];

    private const int LIMIT = 64;

    public function __construct(
        #[Target('iconify.client')]
        private HttpClientInterface $client,
        #[Target('iconify.cache')]
        private CacheInterface $cache,
        #[Autowire(param: 'app.iconify_url')]
        private string $iconifyUrl,
    ) {
    }

    /**
     * Where the browser draws an icon from, for the server not to fetch them one by one.
     */
    public function previewUrl(string $icon): string
    {
        return $this->iconifyUrl.'/'.str_replace(':', '/', $icon).'.svg';
    }

    /**
     * The icons matching the query, best first; the suggestions without one.
     *
     * @return list<string>|null names such as "lucide:house"; null when Iconify can't be reached
     */
    public function search(string $query): ?array
    {
        $query = mb_substr((string) preg_replace('/\s+/u', ' ', mb_strtolower(trim($query))), 0, 100);
        if ('' === $query) {
            return self::SUGGESTIONS;
        }

        try {
            return $this->cache->get('category_icons_'.hash('xxh128', $query), function (ItemInterface $item) use ($query): array {
                $item->expiresAfter(86400);

                /** @var array{icons?: list<string>} $results */
                $results = $this->client->request('GET', '/search', ['query' => [
                    'query' => $query,
                    'prefix' => 'lucide',
                    'limit' => self::LIMIT,
                ]])->toArray();

                return array_values(array_filter($results['icons'] ?? [], static fn (string $icon): bool => str_starts_with($icon, 'lucide:')));
            });
        } catch (ExceptionInterface) {
            // Not cached: the next search tries again
            return null;
        }
    }
}
