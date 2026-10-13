<?php

namespace App\Tests\Command;

use App\Entity\Counterparty;
use App\Entity\RecurringSeries;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Periodicity;
use App\Enum\SeriesStatus;
use App\Tests\EntityFactoryTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class RekeyCounterpartiesCommandTest extends KernelTestCase
{
    use EntityFactoryTrait;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $this->resetDatabase();

        $this->commandTester = new CommandTester(new Application($kernel)->find('app:counterparties:rekey'));
    }

    public function testMergesTheCounterpartiesThatNowTellTheSameOne(): void
    {
        $alice = $this->createUser('alice');
        $account = $this->createAccount($alice);
        $family = $this->createCategory($alice, 'Family');
        // As the normalizer told them before it told the person of a transfer apart from its motive
        $plane = $this->createCounterparty($alice, 'mme nicolau nadege billet d avion');
        $plane->rename('Mum');
        $gift = $this->createCounterparty($alice, 'mme nicolau nadege cadeau');
        $gift->categorize($family);
        $sent = $this->createCounterparty($alice, 'nadege nicolau');
        $sent->rename('Nadège');
        $transactions = [];
        foreach ([
            ["Virement en votre faveur\nVIR INST de MME NICOLAU NADEGE - billet d avion", 30000, $plane],
            ["Virement en votre faveur\nVIR INST de MME NICOLAU NADEGE - cadeau", 5000, $gift],
            ["Virement émis\nVIR INST vers Nadege NICOLAU", -2000, $sent],
        ] as $i => [$label, $amount, $counterparty]) {
            $transactions[$i] = $this->createTransaction($account, \sprintf('2026-0%d-10', $i + 7), $label, $amount);
            $transactions[$i]->assignTo($counterparty);
        }
        // Two series of credits that end up the same: the one the user reviewed stays
        $confirmed = new RecurringSeries($account, $gift, true, Periodicity::Monthly, 5000, 90, new \DateTimeImmutable('2026-08-10'));
        $confirmed->confirm('Pocket money', null);
        $suggested = new RecurringSeries($account, $plane, true, Periodicity::Monthly, 30000, 60, new \DateTimeImmutable('2026-07-10'));
        $this->entityManager->persist($suggested);
        $this->entityManager->persist($confirmed);
        $transactions[0]->attachTo($suggested);
        $transactions[1]->attachTo($confirmed);
        // Someone else's are theirs
        $this->createTransaction($this->createAccount($this->createUser('bob')), '2026-09-10', 'To NADEGE NICOLAU', -1000);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->commandTester->execute([]);

        $this->commandTester->assertCommandIsSuccessful();
        $counterparties = $this->entityManager->getRepository(Counterparty::class)->findBy(['owner' => $alice->getId()]);
        self::assertSame(['nadege nicolau'], array_map(static fn (Counterparty $counterparty): string => $counterparty->getKey(), $counterparties));
        $nadege = $counterparties[0];
        // The name and the category the user gave are kept: its own name, over those of the others
        self::assertSame('Nadège', $nadege->getName());
        self::assertSame('Family', $nadege->getCategory()?->getName());
        self::assertSame(3, $this->entityManager->getRepository(Transaction::class)->count(['counterparty' => $nadege]));

        $series = $this->entityManager->getRepository(RecurringSeries::class)->findAll();
        self::assertCount(1, $series);
        self::assertSame([SeriesStatus::Confirmed, 'Pocket money', $nadege], [$series[0]->getStatus(), $series[0]->getName(), $series[0]->getCounterparty()]);
        self::assertNull($this->entityManager->find(Transaction::class, $transactions[0]->getId())?->getRecurringSeries());

        self::assertSame(1, $this->entityManager->getRepository(Counterparty::class)->count(['key' => 'nadege nicolau', 'owner' => $this->entityManager->getRepository(User::class)->findOneBy(['login' => 'bob'])]));
    }

    public function testTheSeriesTheUserReviewedTakesThePlaceOfASuggestion(): void
    {
        $alice = $this->createUser('alice');
        $account = $this->createAccount($alice);
        $gift = $this->createCounterparty($alice, 'mme nicolau nadege cadeau');
        $nadege = $this->createCounterparty($alice, 'nadege nicolau');
        // Confirmed of the counterparty that goes, suggested of the one that stays
        $confirmed = new RecurringSeries($account, $gift, true, Periodicity::Monthly, 5000, 90, new \DateTimeImmutable('2026-08-10'));
        $confirmed->confirm('Pocket money', null);
        $suggested = new RecurringSeries($account, $nadege, true, Periodicity::Monthly, 5000, 60, new \DateTimeImmutable('2026-09-10'));
        $this->entityManager->persist($confirmed);
        $this->entityManager->persist($suggested);
        $fromGift = $this->createTransaction($account, '2026-08-10', "Virement en votre faveur\nVIR INST de MME NICOLAU NADEGE - cadeau", 5000);
        $fromGift->assignTo($gift);
        $fromGift->attachTo($confirmed);
        $this->createTransaction($account, '2026-09-10', "Virement en votre faveur\nVIR INST de Nadege NICOLAU", 5000)->attachTo($suggested);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->commandTester->execute([]);

        $this->commandTester->assertCommandIsSuccessful();
        $series = $this->entityManager->getRepository(RecurringSeries::class)->findAll();
        self::assertCount(1, $series);
        self::assertSame(['Pocket money', 'nadege nicolau'], [$series[0]->getName(), $series[0]->getCounterparty()->getKey()]);
    }
}
