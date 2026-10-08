<?php

namespace App\Tests\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ButtonTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersDefaultButton(): void
    {
        $crawler = $this->renderTwigComponent('Button', content: 'Save')->crawler();
        $button = $crawler->filter('button[data-slot="button"]');

        self::assertCount(1, $button);
        self::assertSame('Save', trim($button->text()));
        self::assertSame('button', $button->attr('type'));
        self::assertSame('default', $button->attr('data-variant'));
        self::assertStringContainsString('bg-primary', (string) $button->attr('class'));
    }

    public function testRendersLinkWithVariant(): void
    {
        $crawler = $this->renderTwigComponent('Button', [
            'as' => 'a',
            'variant' => 'destructive',
            'href' => '/delete',
        ], 'Delete')->crawler();
        $link = $crawler->filter('a[data-slot="button"]');

        self::assertCount(1, $link);
        self::assertSame('/delete', $link->attr('href'));
        self::assertNull($link->attr('type'));
        self::assertStringContainsString('text-destructive', (string) $link->attr('class'));
        self::assertStringNotContainsString('bg-primary', (string) $link->attr('class'));
    }
}
