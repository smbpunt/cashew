<?php

namespace App\Controller;

use App\EventListener\SessionLocaleListener;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class LocaleController extends AbstractController
{
    /**
     * Switches the interface to another language, then goes back to the page it was switched from.
     *
     * @param list<string> $enabledLocales
     */
    #[Route('/locale/{locale}', name: 'app_locale', methods: ['GET'])]
    public function switch(
        string $locale,
        Request $request,
        #[Autowire('%kernel.enabled_locales%')] array $enabledLocales,
        #[MapQueryParameter] ?string $target = null,
    ): Response {
        if (!\in_array($locale, $enabledLocales, true)) {
            throw $this->createNotFoundException(\sprintf('The "%s" locale isn\'t enabled.', $locale));
        }

        $request->getSession()->set(SessionLocaleListener::SESSION_KEY, $locale);

        // A path of this site only, or the link would redirect anywhere
        if (null === $target || !preg_match('#^/(?![/\\\\])#', $target)) {
            return $this->redirectToRoute('app_home');
        }

        return $this->redirect($target);
    }
}
