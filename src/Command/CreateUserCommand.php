<?php

namespace App\Command;

use App\Entity\User;
use App\Validator\PasswordRequirements;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Ask;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:create-user',
    description: 'Creates a new user, asking for its login and password',
)]
final readonly class CreateUserCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The login of the user')]
        #[Ask('Login', constraints: [new Assert\NotBlank()])]
        string $login,
    ): int {
        $plainPassword = $io->askHidden('Password', function (?string $value): string {
            $violations = $this->validator->validate($value, new PasswordRequirements());
            if (\count($violations) > 0) {
                $messages = [];
                foreach ($violations as $violation) {
                    $messages[] = (string) $violation->getMessage();
                }

                throw new InvalidArgumentException(implode(' ', $messages));
            }

            return (string) $value;
        });

        if ($plainPassword !== $io->askHidden('Repeat the password')) {
            $io->error('The passwords do not match.');

            return Command::FAILURE;
        }

        $user = new User();
        $user->setLogin($login);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error(\sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage()));
            }

            return Command::FAILURE;
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf('User "%s" created.', $user->getUserIdentifier()));

        return Command::SUCCESS;
    }
}
