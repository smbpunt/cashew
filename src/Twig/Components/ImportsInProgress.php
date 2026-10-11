<?php

namespace App\Twig\Components;

use App\Entity\StatementImport;
use App\Entity\User;
use App\Repository\StatementImportRepository;
use App\Statement\StatementImportNotifier;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The user's imports still in progress, wherever they were uploaded from, followed like StatementUpload follows
 * its own: once they're all over, back to the dashboard, which shows what they imported.
 */
#[AsLiveComponent]
#[IsGranted('ROLE_USER')]
final class ImportsInProgress
{
    use DefaultActionTrait;

    /**
     * @var list<StatementImport>|null
     */
    private ?array $imports = null;

    public function __construct(
        private readonly StatementImportRepository $importRepository,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Called when an import's status changes, and while polling.
     */
    #[LiveAction]
    public function refresh(): ?RedirectResponse
    {
        if ([] !== $this->getImports()) {
            return null;
        }

        return new RedirectResponse($this->urlGenerator->generate('app_dashboard'));
    }

    /**
     * @return list<StatementImport>
     */
    public function getImports(): array
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        return $this->imports ??= $this->importRepository->findInProgress($user);
    }

    /**
     * @return list<string>
     */
    public function getTopics(): array
    {
        return array_map(StatementImportNotifier::topic(...), $this->getImports());
    }
}
