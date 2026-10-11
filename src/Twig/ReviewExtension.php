<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\RecurringSeriesRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

final readonly class ReviewExtension
{
    public function __construct(
        private Security $security,
        private RecurringSeriesRepository $series,
    ) {
    }

    /**
     * How many recurring series wait for the user's review.
     */
    #[AsTwigFunction('pending_review_count')]
    public function pendingReviewCount(): int
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->series->countSuggested($user) : 0;
    }
}
