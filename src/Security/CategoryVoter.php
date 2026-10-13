<?php

namespace App\Security;

use App\Entity\Category;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants CATEGORY_DELETE on the user's categories, but the system ones, which the application relies on.
 *
 * @extends Voter<'CATEGORY_DELETE', Category>
 */
final class CategoryVoter extends Voter
{
    public const string DELETE = 'CATEGORY_DELETE';

    public function __construct(
        private readonly Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::DELETE === $attribute && $subject instanceof Category;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return !$subject->isSystem() && $this->security->isGranted(OwnerVoter::OWNER, $subject);
    }
}
