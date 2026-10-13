<?php

namespace App\Command;

use App\Counterparty\CounterpartyRekeyer;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * For the transactions imported before CounterpartyNormalizer told their counterparty the way it does now: to run once
 * after migrating past Version20261013192215, whose series kept the counterparty they were detected with, and after
 * each change of the normalizer. Running it again moves nothing.
 */
#[AsCommand(
    name: 'app:counterparties:rekey',
    description: 'Tells the counterparty of every transaction again, merging those that now tell the same one',
)]
final readonly class RekeyCounterpartiesCommand
{
    public function __construct(
        private UserRepository $users,
        private CounterpartyRekeyer $rekeyer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $rows = [];
        foreach ($this->users->findBy([], ['id' => 'ASC']) as $user) {
            $rows[] = [$user->getUserIdentifier(), $this->rekeyer->rekey($user)];
            $this->entityManager->flush();
        }

        $io->table(['User', 'Transactions moved'], $rows);
        $io->success('Every transaction has its counterparty.');

        return Command::SUCCESS;
    }
}
