<?php

namespace App\Tests\Command;

use App\Entity\Category;
use App\Entity\User;
use App\Tests\EntityFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DefaultCategoryIconsCommandTest extends KernelTestCase
{
    use EntityFactoryTrait;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();

        $this->commandTester = new CommandTester(new Application($kernel)->find('app:categories:default-icons'));
    }

    public function testGivesTheDefaultCategoriesTheirIconInEitherLanguage(): void
    {
        $alice = $this->createUser('alice');
        $maison = $this->createCategory($alice, 'Maison');
        $energie = $this->createCategory($alice, 'énergie', $maison);
        $mine = $this->createCategory($alice, 'Mes trucs');
        $transport = $this->createCategory($alice, 'Transport')->setIcon('lucide:bike');
        $home = $this->createCategory($this->createUser('bob'), 'Home');
        $this->entityManager->flush();

        $this->commandTester->execute([]);

        $this->commandTester->assertCommandIsSuccessful();
        self::assertStringContainsString('3 categories got an icon.', $this->commandTester->getDisplay());
        $this->entityManager->clear();
        self::assertSame(
            ['lucide:house', 'lucide:zap', null, 'lucide:bike', 'lucide:house'],
            array_map(fn (Category $category): ?string => $this->entityManager->find(Category::class, $category->getId())?->getIcon(), [$maison, $energie, $mine, $transport, $home]),
        );

        // Once is enough
        $this->commandTester->execute([]);
        self::assertStringContainsString('No category to give an icon to.', $this->commandTester->getDisplay());
    }

    public function testOnlyListsThemOnADryRun(): void
    {
        $maison = $this->createCategory($this->createUser('alice'), 'Maison');
        $this->entityManager->flush();

        $this->commandTester->execute(['--dry-run' => true]);

        $this->commandTester->assertCommandIsSuccessful();
        self::assertStringContainsString('alice', $this->commandTester->getDisplay());
        self::assertStringContainsString('lucide:house', $this->commandTester->getDisplay());
        self::assertStringContainsString('1 categories would get an icon', $this->commandTester->getDisplay());
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Category::class, $maison->getId())?->getIcon());
    }
}
