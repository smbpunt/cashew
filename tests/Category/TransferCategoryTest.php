<?php

namespace App\Tests\Category;

use App\Category\TransferCategory;
use App\Entity\Category;
use App\Entity\User;
use App\Enum\CategoryRole;
use App\Tests\EntityFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TransferCategoryTest extends KernelTestCase
{
    use EntityFactoryTrait;

    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();

        $this->user = $this->createUser('alice');
    }

    public function testCreatesItOnceInTheLanguageOfTheRequest(): void
    {
        $transfers = self::getContainer()->get(TransferCategory::class);

        $category = $transfers->of($this->user);
        // Before a flush, then after
        self::assertSame($category, $transfers->of($this->user));
        $this->entityManager->flush();
        self::assertSame($category, $transfers->of($this->user));

        self::assertSame(['Transfers', 'lucide:arrow-left-right', CategoryRole::Transfers, 'transfers'], [$category->getName(), $category->getIcon(), $category->getRole(), $category->getDefaultKey()]);
        self::assertSame(1, $this->entityManager->getRepository(Category::class)->count([]));
    }

    public function testATopCategoryOfTheUserByThatNameBecomesIt(): void
    {
        $mine = $this->createCategory($this->user, 'Transfers');
        $this->entityManager->flush();

        self::assertSame($mine, self::getContainer()->get(TransferCategory::class)->of($this->user));
        self::assertTrue($mine->isSystem());
        // Still the user's: named as they did, whatever the language
        self::assertNull($mine->getDefaultKey());
    }
}
