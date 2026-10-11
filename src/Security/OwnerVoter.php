<?php

namespace App\Security;

use App\Entity\Category;
use App\Entity\RecurringSeries;
use App\Entity\Transaction;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants OWNER on what belongs to the user: their categories, series and transactions.
 *
 * @extends Voter<'OWNER', Category|RecurringSeries|Transaction>
 */
final class OwnerVoter extends Voter
{
    public const string OWNER = 'OWNER';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::OWNER === $attribute && ($subject instanceof Category || $subject instanceof RecurringSeries || $subject instanceof Transaction);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $owner = $subject instanceof Transaction ? $subject->getAccount()->getOwner() : $subject->getOwner();

        return $owner === $user || $owner->getId() === $user->getId();
    }
}
