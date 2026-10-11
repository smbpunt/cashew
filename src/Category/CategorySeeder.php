<?php

namespace App\Category;

use App\Entity\Category;
use App\Entity\User;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gives a user a first set of categories, named in their language, for them to adapt: Home and its bills, Income...
 */
final readonly class CategorySeeder
{
    /**
     * Translation keys of the top categories, and of their subcategories.
     */
    private const array DEFAULTS = [
        'home' => ['rent', 'loan', 'energy', 'water', 'insurance', 'internet', 'maintenance'],
        'groceries' => ['supermarket', 'restaurants'],
        'transport' => ['fuel', 'public_transport', 'car_insurance', 'car_maintenance', 'tolls_parking'],
        'subscriptions' => ['phone', 'streaming'],
        'health' => ['doctor', 'pharmacy', 'health_insurance'],
        'leisure' => ['outings', 'travel', 'sport'],
        'shopping' => ['clothing', 'electronics', 'gifts'],
        'taxes' => ['income_tax', 'local_taxes'],
        'bank' => ['bank_fees'],
        'savings' => [],
        'income' => ['salary', 'benefits', 'refunds'],
    ];

    /**
     * Their icons, among those in assets/icons.
     */
    private const array ICONS = [
        'home' => 'lucide:house',
        'rent' => 'lucide:key-round',
        'loan' => 'lucide:hand-coins',
        'energy' => 'lucide:zap',
        'water' => 'lucide:droplet',
        'insurance' => 'lucide:shield-check',
        'internet' => 'lucide:wifi',
        'maintenance' => 'lucide:hammer',
        'groceries' => 'lucide:shopping-basket',
        'supermarket' => 'lucide:shopping-cart',
        'restaurants' => 'lucide:utensils',
        'transport' => 'lucide:car',
        'fuel' => 'lucide:fuel',
        'public_transport' => 'lucide:bus',
        'car_insurance' => 'lucide:shield',
        'car_maintenance' => 'lucide:wrench',
        'tolls_parking' => 'lucide:square-parking',
        'subscriptions' => 'lucide:repeat',
        'phone' => 'lucide:smartphone',
        'streaming' => 'lucide:tv',
        'health' => 'lucide:heart-pulse',
        'doctor' => 'lucide:stethoscope',
        'pharmacy' => 'lucide:pill',
        'health_insurance' => 'lucide:shield-plus',
        'leisure' => 'lucide:party-popper',
        'outings' => 'lucide:ticket',
        'travel' => 'lucide:plane',
        'sport' => 'lucide:dumbbell',
        'shopping' => 'lucide:shopping-bag',
        'clothing' => 'lucide:shirt',
        'electronics' => 'lucide:laptop',
        'gifts' => 'lucide:gift',
        'taxes' => 'lucide:receipt',
        'income_tax' => 'lucide:file-text',
        'local_taxes' => 'lucide:building',
        'bank' => 'lucide:landmark',
        'bank_fees' => 'lucide:coins',
        'savings' => 'lucide:piggy-bank',
        'income' => 'lucide:wallet',
        'salary' => 'lucide:banknote',
        'benefits' => 'lucide:hand-heart',
        'refunds' => 'lucide:undo-2',
    ];

    public function __construct(
        private TranslatorInterface $translator,
        private EntityManagerInterface $entityManager,
        private CategoryRepository $categories,
        /** @var list<string> */
        #[Autowire(param: 'kernel.enabled_locales')]
        private array $locales,
    ) {
    }

    /**
     * The icons of the default categories by their name, in every language of the interface and in lowercase: "maison"
     * and "home" for the house.
     *
     * @return array<string, string>
     */
    public function defaultIcons(): array
    {
        $icons = [];
        foreach ($this->locales as $locale) {
            foreach (self::ICONS as $key => $icon) {
                $icons[mb_strtolower($this->translator->trans('category.defaults.'.$key, locale: $locale))] = $icon;
            }
        }

        return $icons;
    }

    /**
     * Seeds the categories the first time only, for those the user deleted not to come back; and not at all if they
     * made their own already. Flushes.
     */
    public function seedOnce(User $owner): void
    {
        if ($owner->hasSeededCategories()) {
            return;
        }

        if ($this->categories->hasAny($owner)) {
            $owner->markCategoriesSeeded();
            $this->entityManager->flush();

            return;
        }

        $this->seed($owner);
    }

    /**
     * Persists the categories, and flushes them.
     */
    public function seed(User $owner): void
    {
        foreach (self::DEFAULTS as $key => $children) {
            $parent = $this->create($owner, $key, null);
            foreach ($children as $child) {
                $this->create($owner, $child, $parent);
            }
        }
        $owner->markCategoriesSeeded();

        $this->entityManager->flush();
    }

    private function create(User $owner, string $key, ?Category $parent): Category
    {
        $category = new Category($owner)
            ->setName($this->translator->trans('category.defaults.'.$key))
            ->setIcon(self::ICONS[$key])
            ->setParent($parent);
        $this->entityManager->persist($category);

        return $category;
    }
}
