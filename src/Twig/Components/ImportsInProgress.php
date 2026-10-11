<?php

namespace App\Twig\Components;

use App\Entity\StatementImport;
use App\Entity\User;
use App\Repository\StatementImportRepository;
use App\Statement\ImportDestination;
use App\Statement\StatementImportNotifier;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The user's imports still in progress, wherever they were uploaded from, followed like StatementUpload follows
 * its own: once they're all over, to the review of the recurring series they brought up, if any, or back to the
 * dashboard, which shows what they imported.
 */
#[AsLiveComponent]
#[IsGranted('ROLE_USER')]
final class ImportsInProgress
{
    use DefaultActionTrait;

    /**
     * The imports seen in progress, for the review to sum them up once they're over.
     *
     * @var list<int>
     */
    #[LiveProp]
    public array $importIds = [];

    /**
     * @var list<StatementImport>|null
     */
    private ?array $imports = null;

    public function __construct(
        private readonly StatementImportRepository $importRepository,
        private readonly ImportDestination $destination,
        private readonly Security $security,
    ) {
    }

    public function mount(): void
    {
        // Mounted before IsGranted is checked, which refuses the component to an anonymous user anyway
        if ($this->security->getUser() instanceof User) {
            $this->follow();
        }
    }

    /**
     * Called when an import's status changes, and while polling.
     */
    #[LiveAction]
    public function refresh(): ?RedirectResponse
    {
        $this->follow();
        if ([] !== $this->getImports()) {
            return null;
        }

        return new RedirectResponse($this->destination->url($this->user(), $this->importIds));
    }

    /**
     * @return list<StatementImport>
     */
    public function getImports(): array
    {
        return $this->imports ??= $this->importRepository->findInProgress($this->user());
    }

    /**
     * @return list<string>
     */
    public function getTopics(): array
    {
        return array_map(StatementImportNotifier::topic(...), $this->getImports());
    }

    /**
     * Adds the imports in progress to those seen, the latest uploaded since included.
     */
    private function follow(): void
    {
        $ids = array_map(static fn (StatementImport $import): int => (int) $import->getId(), $this->getImports());
        $this->importIds = array_values(array_unique([...$this->importIds, ...$ids]));
    }

    private function user(): User
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
