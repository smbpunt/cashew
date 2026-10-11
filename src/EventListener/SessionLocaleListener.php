<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applies the language picked through LocaleController, kept in the session; without one, the default locale.
 *
 * Runs before Symfony's LocaleListener (priority 16), which keeps a locale already set.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
final readonly class SessionLocaleListener
{
    public const string SESSION_KEY = '_locale';

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // Never starts a session for a visitor who didn't pick a language
        if (!$request->hasPreviousSession()) {
            return;
        }

        $locale = $request->getSession()->get(self::SESSION_KEY);
        if (\is_string($locale)) {
            $request->setLocale($locale);
        }
    }
}
