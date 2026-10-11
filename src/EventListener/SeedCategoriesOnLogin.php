<?php

namespace App\EventListener;

use App\Category\CategorySeeder;
use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Gives a user the default categories as they first log in: in the language of the login page, which
 * SessionLocaleListener applied before the firewall ran.
 */
#[AsEventListener]
final readonly class SeedCategoriesOnLogin
{
    public function __construct(
        private CategorySeeder $seeder,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof User) {
            $this->seeder->seedOnce($user);
        }
    }
}
