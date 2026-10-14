<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Submits a form as a browser running assets/controllers/csrf_protection_controller.js does: its stateless CSRF token,
 * if the script finds it, double-submitted in the field and in a cookie.
 */
trait SubmitsLikeABrowserTrait
{
    private const string CSRF_COOKIE = 'csrf-token';

    /**
     * @param array<string, string> $values
     */
    private function submitLikeABrowser(KernelBrowser $client, Crawler $crawler, string $button, array $values = []): Crawler
    {
        $form = $crawler->selectButton($button)->form();
        // The field the script looks for, and only that one
        $field = new Crawler($form->getFormNode())->filter('input[data-controller="csrf-protection"], input[name="_csrf_token"]');

        if ($field->count() > 0 && self::CSRF_COOKIE === $field->attr('value')) {
            $values[(string) $field->attr('name')] = $this->doubleSubmittedCsrfToken($client);
        }

        return $client->submit($form, $values, ['HTTP_ORIGIN' => 'http://localhost']);
    }

    /**
     * A random token, and the cookie that tells it is the one the browser submitted.
     */
    private function doubleSubmittedCsrfToken(KernelBrowser $client): string
    {
        $token = strtr(base64_encode(random_bytes(18)), '+/', '-_');
        $client->getCookieJar()->set(new Cookie(self::CSRF_COOKIE.'_'.$token, self::CSRF_COOKIE));

        return $token;
    }
}
