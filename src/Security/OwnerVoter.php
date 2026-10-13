<?php

namespace App\Security;

use App\Entity\Owned;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants OWNER on what belongs to the user: their categories, series, transactions...
 *
 * @extends Voter<'OWNER', Owned>
 */
final class OwnerVoter extends Voter
{
    public const string OWNER = 'OWNER';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::OWNER === $attribute && $subject instanceof Owned;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $owner = $subject->getOwner();

        return $owner === $user || $owner->getId() === $user->getId();
    }
}
