<?php

namespace App\Statement;

use App\Entity\User;
use App\Repository\RecurringSeriesRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where to go once imports are over: to the review of the recurring series they brought up, if any, or else to the
 * dashboard, which shows what they imported.
 */
final readonly class ImportDestination
{
    public function __construct(
        private RecurringSeriesRepository $series,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param list<int> $importIds the imports for the review to sum up
     */
    public function url(User $user, array $importIds = []): string
    {
        return $this->series->countSuggested($user) > 0
            ? $this->urlGenerator->generate('app_review', ['imports' => $importIds])
            : $this->urlGenerator->generate('app_dashboard');
    }
}
