<?php

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testSerializationKeepsThePasswordHashOut(): void
    {
        $user = new User()->setLogin('alice')->setPassword('$2y$13$the-full-password-hash');

        self::assertStringNotContainsString('the-full-password-hash', serialize($user));
    }

    public function testAUserWithoutPasswordCanBeSerialized(): void
    {
        $user = new User()->setLogin('alice');

        self::assertSame('alice', unserialize(serialize($user))->getLogin());
    }
}
