<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

trait SwitchesLocaleTrait
{
    use SubmitsLikeABrowserTrait;

    /**
     * As the switcher of a page of the site does: from it, with its token double-submitted.
     */
    private function switchLocale(KernelBrowser $client, string $locale, ?string $target = null): void
    {
        $uri = '/locale/'.$locale.(null === $target ? '' : '?'.http_build_query(['target' => $target]));
        $client->request('POST', $uri, ['_csrf_token' => $this->doubleSubmittedCsrfToken($client)], server: ['HTTP_ORIGIN' => 'http://localhost']);
    }
}
