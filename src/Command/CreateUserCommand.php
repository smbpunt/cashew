<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Validator\PasswordRequirements;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Ask;
use Symfony\Component\Console\Attribute\Option;
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
        private UserRepository $userRepository,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The login of the user')]
        #[Ask('Login', constraints: [new Assert\NotBlank()])]
        string $login,
        // Unattended (e.g. the Docker entrypoint): the password stays out of the command line
        #[Option('Read the password from this environment variable instead of asking for it')]
        ?string $passwordEnv = null,
        #[Option('Leave the user as is if the login exists already, instead of failing')]
        bool $ifMissing = false,
    ): int {
        if ($ifMissing && null !== $this->userRepository->loadUserByIdentifier($login)) {
            $io->note(\sprintf('User "%s" exists already: left as is.', User::normalizeLogin($login)));

            return Command::SUCCESS;
        }

        $plainPassword = null === $passwordEnv ? $this->askPassword($io) : $this->passwordFromEnv($io, $passwordEnv);
        if (null === $plainPassword) {
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

    private function askPassword(SymfonyStyle $io): ?string
    {
        $plainPassword = $io->askHidden('Password', function (?string $value): string {
            $messages = $this->passwordViolations($value);
            if ([] !== $messages) {
                throw new InvalidArgumentException(implode(' ', $messages));
            }

            return (string) $value;
        });

        if ($plainPassword !== $io->askHidden('Repeat the password')) {
            $io->error('The passwords do not match.');

            return null;
        }

        return $plainPassword;
    }

    private function passwordFromEnv(SymfonyStyle $io, string $name): ?string
    {
        $plainPassword = getenv($name);
        if (false === $plainPassword) {
            $io->error(\sprintf('The environment variable "%s" is not set.', $name));

            return null;
        }

        $messages = $this->passwordViolations($plainPassword);
        if ([] !== $messages) {
            $io->error(\sprintf('%s: %s', $name, implode(' ', $messages)));

            return null;
        }

        return $plainPassword;
    }

    /**
     * @return list<string>
     */
    private function passwordViolations(?string $plainPassword): array
    {
        $messages = [];
        foreach ($this->validator->validate($plainPassword, new PasswordRequirements()) as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
    }
}
