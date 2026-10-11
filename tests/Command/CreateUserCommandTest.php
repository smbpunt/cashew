<?php

namespace App\Tests\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateUserCommandTest extends KernelTestCase
{
    private const string PASSWORD = 'Correct-Horse-Battery-9';

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        self::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('DELETE FROM '.User::class)
            ->execute();

        $this->commandTester = new CommandTester(new Application($kernel)->find('app:create-user'));
    }

    public function testItAsksForLoginAndPasswordAndStoresAHashedPassword(): void
    {
        $this->commandTester->setInputs(['alice', self::PASSWORD, self::PASSWORD]);

        $this->commandTester->execute([]);

        $this->commandTester->assertCommandIsSuccessful();
        self::assertStringContainsString('User "alice" created.', $this->commandTester->getDisplay());

        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['login' => 'alice']);
        self::assertInstanceOf(User::class, $user);
        self::assertNotSame(self::PASSWORD, $user->getPassword());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, self::PASSWORD));
    }

    public function testLoginCanBePassedAsArgument(): void
    {
        $this->commandTester->setInputs([self::PASSWORD, self::PASSWORD]);

        $this->commandTester->execute(['login' => 'bob']);

        $this->commandTester->assertCommandIsSuccessful();
        self::assertNotNull(self::getContainer()->get(UserRepository::class)->findOneBy(['login' => 'bob']));
    }

    public function testLoginIsStoredLowercased(): void
    {
        $this->commandTester->setInputs([' Alice ', self::PASSWORD, self::PASSWORD]);

        $this->commandTester->execute([]);

        $this->commandTester->assertCommandIsSuccessful();
        self::assertNotNull(self::getContainer()->get(UserRepository::class)->findOneBy(['login' => 'alice']));
    }

    public function testItRejectsALoginAlreadyTakenWhateverItsCase(): void
    {
        $this->commandTester->setInputs([self::PASSWORD, self::PASSWORD]);
        $this->commandTester->execute(['login' => 'alice']);

        $this->commandTester->setInputs([self::PASSWORD, self::PASSWORD]);
        $this->commandTester->execute(['login' => 'ALICE']);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString('This login is already taken.', $this->commandTester->getDisplay());
        self::assertCount(1, self::getContainer()->get(UserRepository::class)->findAll());
    }

    #[DataProvider('validLogins')]
    public function testItAcceptsUsualLoginFormats(string $login): void
    {
        $this->commandTester->setInputs([self::PASSWORD, self::PASSWORD]);

        $this->commandTester->execute(['login' => $login]);

        $this->commandTester->assertCommandIsSuccessful();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validLogins(): iterable
    {
        yield 'dot' => ['nicolau.b'];
        yield 'hyphen' => ['jean-pierre'];
        yield 'underscore and digits' => ['user_42'];
        yield 'uppercase, lowercased before validation' => ['Nicolau.B'];
    }

    #[DataProvider('invalidLogins')]
    public function testItRejectsInvalidLogins(string $login, string $expectedMessage): void
    {
        $this->commandTester->setInputs([self::PASSWORD, self::PASSWORD]);

        $this->commandTester->execute(['login' => $login]);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString($expectedMessage, $this->commandTester->getDisplay());
        self::assertCount(0, self::getContainer()->get(UserRepository::class)->findAll());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidLogins(): iterable
    {
        $charset = 'Use only letters, digits, and . _ - between them.';

        yield 'too short' => ['al', 'This value is too short.'];
        yield 'cyrillic look-alike' => ["\u{0430}lice", $charset];
        yield 'accented letter' => ["jos\u{00E9}", $charset];
        yield 'combining accent' => ["jose\u{0301}", $charset];
        yield 'no-break space' => ["alice\u{00A0}", $charset];
        yield 'zero-width space' => ["ali\u{200B}ce", $charset];
        yield 'right-to-left override' => ["\u{202E}ecila", $charset];
        yield 'inner space' => ['ali ce', $charset];
        yield 'leading separator' => ['.alice', $charset];
        yield 'trailing separator' => ['alice.', $charset];
        yield 'double separator' => ['a..b', $charset];
    }

    public function testItAsksAgainForAPasswordThatIsTooWeak(): void
    {
        $this->commandTester->setInputs(['alice', '', 'short', 'aaaaaaaaaaaa', self::PASSWORD, self::PASSWORD]);

        $this->commandTester->execute([]);

        $this->commandTester->assertCommandIsSuccessful();
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('This value should not be blank.', $display);
        self::assertStringContainsString('This value is too short.', $display);
        self::assertStringContainsString('The password strength is too low.', $display);
    }

    public function testItFailsWhenPasswordsDoNotMatch(): void
    {
        $this->commandTester->setInputs(['alice', self::PASSWORD, 'other']);

        $this->commandTester->execute([]);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        self::assertStringContainsString('The passwords do not match.', $this->commandTester->getDisplay());
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneBy(['login' => 'alice']));
    }
}
