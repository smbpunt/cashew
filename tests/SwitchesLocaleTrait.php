<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

trait SwitchesLocaleTrait
{
    /**
     * As the switcher of a page of the site does: from it, with its token.
     */
    private function switchLocale(KernelBrowser $client, string $locale, ?string $target = null): void
    {
        $uri = '/locale/'.$locale.(null === $target ? '' : '?'.http_build_query(['target' => $target]));
        $client->request('POST', $uri, ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);
    }
}
