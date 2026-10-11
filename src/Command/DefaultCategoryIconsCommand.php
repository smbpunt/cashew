<?php

namespace App\Command;

use App\Category\CategorySeeder;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * For the categories seeded before they had icons: those named as a default category get its icon, whoever they belong
 * to. The others, and those with an icon already, are left as they are.
 */
#[AsCommand(
    name: 'app:categories:default-icons',
    description: 'Gives the categories without an icon that of the default category of the same name',
)]
final readonly class DefaultCategoryIconsCommand
{
    public function __construct(
        private CategoryRepository $categories,
        private CategorySeeder $seeder,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Lists the icons it would give, without saving them')]
        bool $dryRun = false,
    ): int {
        $icons = $this->seeder->defaultIcons();

        $rows = [];
        foreach ($this->categories->findBy(['icon' => null], ['id' => 'ASC']) as $category) {
            $icon = $icons[mb_strtolower($category->getName())] ?? null;
            if (null === $icon) {
                continue;
            }

            $category->setIcon($icon);
            $rows[] = [$category->getOwner()->getUserIdentifier(), $category->getPath(), $icon];
        }

        if ([] === $rows) {
            $io->success('No category to give an icon to.');

            return Command::SUCCESS;
        }

        $io->table(['User', 'Category', 'Icon'], $rows);

        if ($dryRun) {
            $io->note(\sprintf('%d categories would get an icon: run it without --dry-run to save them.', \count($rows)));

            return Command::SUCCESS;
        }

        $this->entityManager->flush();
        $io->success(\sprintf('%d categories got an icon.', \count($rows)));

        return Command::SUCCESS;
    }
}
