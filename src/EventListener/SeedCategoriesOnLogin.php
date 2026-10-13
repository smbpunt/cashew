<?php

namespace App\EventListener;

use App\Category\CategorySeeder;
use App\Category\TransferCategory;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Gives a user the default categories as they first log in, and the "Transfers" one if they lack it: in the language of the login page, which
 * SessionLocaleListener applied before the firewall ran.
 */
#[AsEventListener]
final readonly class SeedCategoriesOnLogin
{
    public function __construct(
        private CategorySeeder $seeder,
        private TransferCategory $transfers,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof User) {
            $this->seeder->seedOnce($user);
            $this->transfers->of($user);
            $this->entityManager->flush();
        }
    }
}
