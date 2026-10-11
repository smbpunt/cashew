<?php

namespace App\Twig\Components;

use App\Entity\RecurringSeries;
use App\Entity\User;
use App\Repository\RecurringSeriesRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The recurring series waiting for the user's review, the surest first: each one leaves the list once reviewed.
 */
#[AsLiveComponent]
#[IsGranted('ROLE_USER')]
final class PendingSeries
{
    use DefaultActionTrait;

    /**
     * @var list<RecurringSeries>|null
     */
    private ?array $series = null;

    public function __construct(
        private readonly RecurringSeriesRepository $repository,
        private readonly Security $security,
    ) {
    }

    #[LiveListener('series:reviewed')]
    public function onReviewed(): void
    {
        $this->series = null;
    }

    /**
     * @return list<RecurringSeries>
     */
    public function getSeries(): array
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        return $this->series ??= $this->repository->findSuggested($user);
    }
}
