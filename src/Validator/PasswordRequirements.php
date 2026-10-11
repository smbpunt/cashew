<?php

namespace App\Validator;

use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * Rules a plain password must follow before it is hashed.
 *
 * The User entity only ever holds the hash, so these rules belong on the plain
 * password wherever one is entered (console command, registration form...).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::TARGET_PARAMETER)]
final class PasswordRequirements extends Compound
{
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\NotBlank(),
            new Assert\Length(min: 12, max: PasswordHasherInterface::MAX_PASSWORD_LENGTH),
            new Assert\PasswordStrength(),
        ];
    }
}
