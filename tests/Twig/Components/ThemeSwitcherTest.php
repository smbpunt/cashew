<?php

namespace App\Tests\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ThemeSwitcherTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testOffersTheThreeThemesAsLabelledToggleButtons(): void
    {
        $crawler = $this->renderTwigComponent('ThemeSwitcher')->crawler();
        $group = $crawler->filter('[role="group"][data-controller="theme"]');

        self::assertCount(1, $group);
        self::assertSame('Theme', $group->attr('aria-label'));

        $options = $group->filter('button[data-theme-target="option"]');
        self::assertSame(['light', 'soft', 'dark'], $options->extract(['data-theme-theme-param']));
        self::assertSame(['Light', 'Soft light', 'Dark'], $options->extract(['aria-label']));
        self::assertSame(['Light', 'Soft light', 'Dark'], $options->extract(['title']));
        // The chosen theme is only known to the browser, which presses its button
        self::assertSame(['false', 'false', 'false'], $options->extract(['aria-pressed']));
        self::assertSame(['theme#choose', 'theme#choose', 'theme#choose'], $options->extract(['data-action']));
        self::assertSame(['icon-sm', 'icon-sm', 'icon-sm'], $options->extract(['data-size']));
    }

    public function testTakesTheButtonSizeAndPassesAttributesToTheGroup(): void
    {
        $crawler = $this->renderTwigComponent('ThemeSwitcher', ['size' => 'icon-lg', 'class' => 'rounded-full'])->crawler();
        $group = $crawler->filter('[data-controller="theme"]');

        self::assertStringContainsString('rounded-full', (string) $group->attr('class'));
        self::assertSame(['icon-lg', 'icon-lg', 'icon-lg'], $group->filter('button')->extract(['data-size']));
    }
}
