<?php

namespace App\Twig\Components;

use App\Category\IconSearch;
use App\Entity\Category;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The icon of a category, searched among the Lucide icons.
 *
 * Emits "icon:picked" up with the icon, for a live form to take it; a form submitted as usual takes it from the hidden
 * field it's given the name of.
 */
#[AsLiveComponent]
#[IsGranted('ROLE_USER')]
final class IconPicker
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    /**
     * The name of the hidden field holding the icon, if any: "category[icon]".
     */
    #[LiveProp]
    public ?string $name = null;

    #[LiveProp(updateFromParent: true)]
    public ?string $value = null;

    #[LiveProp(writable: true)]
    public string $query = '';

    public function __construct(
        private readonly IconSearch $icons,
    ) {
    }

    /**
     * Shows the icon only if it's one of those offered: a form sent back invalid may hold anything.
     */
    public function mount(?string $value = null): void
    {
        $this->value = self::valid($value);
    }

    /**
     * Takes the icon, or none without one.
     */
    #[LiveAction]
    public function pick(#[LiveArg] ?string $icon = null): void
    {
        $this->value = self::valid($icon);
        $this->emitUp('icon:picked', ['icon' => $this->value]);
    }

    /**
     * @return list<string>|null null when the icons can't be searched
     */
    public function getResults(): ?array
    {
        return $this->icons->search($this->query);
    }

    /**
     * Where the browser draws an icon of the results from, for the server not to fetch them one by one.
     */
    public function preview(string $icon): string
    {
        return 'https://api.iconify.design/'.str_replace(':', '/', $icon).'.svg';
    }

    private static function valid(?string $icon): ?string
    {
        return null !== $icon && preg_match(Category::ICON_PATTERN, $icon) ? $icon : null;
    }
}
