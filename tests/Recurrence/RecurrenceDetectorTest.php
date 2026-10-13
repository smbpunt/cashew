<?php

namespace App\Tests\Recurrence;

use App\Entity\BankAccount;
use App\Entity\RecurringSeries;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Periodicity;
use App\Enum\SeriesStatus;
use App\Enum\TransactionKind;
use App\Recurrence\RecurrenceDetector;
use App\Tests\EntityFactoryTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecurrenceDetectorTest extends KernelTestCase
{
    use EntityFactoryTrait;

    private User $user;
    private BankAccount $account;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();

        $this->user = $this->createUser('alice');
        $this->account = $this->createAccount($this->user);
    }

    public function testSuggestsTheTransactionsThatComeBack(): void
    {
        foreach (['06', '07', '08', '09'] as $month) {
            $this->add("2026-$month-15", "Prélèvement\nFREE MOBILE\nREF/{$month}0042 MDT/++FM{$month}A", -1999);
            $this->add("2026-$month-28", "Virement en votre faveur\nVIR SEPA SALAIRE\nACME SAS", 250000);
            // A refund isn't one of the purchases
            $this->add("2026-$month-0".((int) $month % 3 + 1), "Paiement par carte\nAMAZON", -2500 - (int) $month);
        }
        $this->add('2026-08-20', "Remboursement\nAMAZON", 2500);

        $series = $this->detect();

        self::assertSame([
            ['Amazon', false, Periodicity::Monthly, -2508],
            ['Free Mobile', false, Periodicity::Monthly, -1999],
            ['Salaire Acme Sas', true, Periodicity::Monthly, 250000],
        ], array_map(static fn (RecurringSeries $series): array => [$series->getName(), $series->isIncome(), $series->getPeriodicity(), $series->getTypicalAmount()], $series));
        self::assertSame([SeriesStatus::Suggested], array_values(array_unique(array_map(static fn (RecurringSeries $series): SeriesStatus => $series->getStatus(), $series), \SORT_REGULAR)));

        self::assertSame(4, $this->countAttached($series[1]));
        // Not the refund
        self::assertSame(4, $this->countAttached($series[0]));
    }

    public function testLeavesOutWhatIsNeitherAtThePriceNorOnTime(): void
    {
        foreach (['03', '04', '05', '06'] as $month) {
            $this->add("2026-$month-20", "Prélèvement\nNETFLIX", -1349);
        }
        foreach (['07', '08', '09'] as $month) {
            $this->add("2026-$month-20", "Prélèvement\nNETFLIX", -1549);
        }
        // A gift card, from the same counterparty
        $this->add('2026-08-03', "Prélèvement\nNETFLIX", -2500);

        [$series] = $this->detect();

        self::assertSame(-1549, $series->getTypicalAmount());
        // At the old price too, on time
        self::assertSame(7, $this->countAttached($series));
        self::assertNull($this->findOne('2026-08-03')->getRecurringSeries());
    }

    public function testTheOtherPurchasesOfTheShopOfASubscriptionDoNotJoinIt(): void
    {
        foreach (['06', '07', '08'] as $month) {
            $this->add("2026-$month-05", "Prélèvement\nAMAZON PRIME", -699);
        }
        [$series] = $this->detect();
        $series->confirm('Prime', null);
        $this->entityManager->flush();

        $this->add('2026-08-21', "Prélèvement\nAMAZON PRIME", -3499);
        $this->add('2026-09-05', "Prélèvement\nAMAZON PRIME", -899);
        $this->add('2026-09-12', "Prélèvement\nAMAZON PRIME", -1250);
        [$series] = $this->detect();

        // The new price, on time, but not the purchases
        self::assertSame(4, $this->countAttached($series));
        self::assertSame($series->getId(), $this->findOne('2026-09-05')->getRecurringSeries()?->getId());
        self::assertNull($this->findOne('2026-08-21')->getRecurringSeries());
        self::assertNull($this->findOne('2026-09-12')->getRecurringSeries());
        self::assertSame(Periodicity::Monthly, $series->getPeriodicity());
    }

    public function testASuggestionThatNoLongerHoldsGoesAway(): void
    {
        foreach (['04', '05', '06'] as $month) {
            $this->add("2026-$month-15", "Prélèvement\nFREE MOBILE", -1999);
            $this->add("2026-$month-20", "Prélèvement\nNETFLIX", -1349);
        }
        [, $netflix] = $this->detect();
        $netflix->confirm('Netflix', null);
        $this->entityManager->flush();

        // Both stopped, months ago
        $this->add('2026-09-30', 'BOULANGERIE DU PORT', -450);
        $series = $this->detect();

        self::assertCount(1, $series);
        self::assertSame(SeriesStatus::Confirmed, $series[0]->getStatus());
        self::assertSame(3, $this->countAttached($series[0]));
        // Those of Free Mobile, and the new one
        self::assertSame(4, $this->entityManager->getRepository(Transaction::class)->count(['recurringSeries' => null]));
    }

    public function testTheMonthlyDebitOfADeferredDebitCardIsNotOne(): void
    {
        // Imported without the card statement
        foreach (['06', '07', '08', '09'] as $month) {
            $this->createTransaction($this->account, "2026-$month-30", "Prélèvement carte\nDEBIT MENSUEL CARTE 497010XXXXXX1234", -8000, TransactionKind::CardSettlement);
        }

        self::assertSame([], $this->detect());
    }

    public function testDoesNotSuggestWhatStopped(): void
    {
        foreach (['01', '02', '03'] as $month) {
            $this->add("2026-$month-20", "Prélèvement\nNETFLIX", -1349);
        }
        $this->add('2026-09-30', "Prélèvement\nFREE MOBILE", -1999);

        self::assertSame([], $this->detect());
    }

    public function testARejectedSeriesIsNotSuggestedAgain(): void
    {
        foreach (['06', '07', '08'] as $month) {
            $this->add("2026-$month-15", "Prélèvement\nFREE MOBILE", -1999);
        }
        [$series] = $this->detect();
        $series->reject();
        $this->entityManager->flush();

        $this->add('2026-09-15', "Prélèvement\nFREE MOBILE", -1999);
        [$series] = $this->detect();

        self::assertSame(SeriesStatus::Rejected, $series->getStatus());
        self::assertSame(3, $this->countAttached($series));
    }

    public function testTheNewTransactionsJoinAConfirmedSeries(): void
    {
        foreach (['06', '07', '08'] as $month) {
            $this->add("2026-$month-05", "Prélèvement\nEDF CLIENTS PARTICULIERS", -8500);
        }
        [$series] = $this->detect();
        $series->confirm('EDF', null);
        $this->entityManager->flush();

        // Even at a new price
        $this->add('2026-09-04', "Prélèvement\nEDF CLIENTS PARTICULIERS\nECH/040926 MDT/EDF778899", -9200);
        [$series] = $this->detect();

        self::assertSame(SeriesStatus::Confirmed, $series->getStatus());
        self::assertSame('EDF', $series->getName());
        self::assertSame(4, $this->countAttached($series));
        self::assertSame('2026-09-04', $series->getLastSeenOn()->format('Y-m-d'));
    }

    public function testASeriesTheUserSaidEndedGoesOnWithAnOccurrenceAfter(): void
    {
        foreach (['06', '07', '08'] as $month) {
            $this->add("2026-$month-05", "Prélèvement\nNETFLIX", -1349);
        }
        [$series] = $this->detect();
        $series->confirm('Netflix', null);
        $series->end(new \DateTimeImmutable('2026-09-10'));
        $this->entityManager->flush();

        // The last one, before it ended
        $this->add('2026-09-05', "Prélèvement\nNETFLIX", -1349);
        [$series] = $this->detect();
        self::assertSame('2026-09-05', $series->getLastSeenOn()->format('Y-m-d'));
        self::assertTrue($series->isEnded());

        $this->add('2026-10-05', "Prélèvement\nNETFLIX", -1349);
        [$series] = $this->detect();
        self::assertFalse($series->isEnded());
    }

    public function testTwoContractsOfTheSameCounterpartyAreTwoSeries(): void
    {
        foreach (['07', '08', '09'] as $month) {
            $this->addGenerali($month);
        }

        $series = $this->detect();

        self::assertSame([
            ['Generali Iard Sa Generali Contrat · AU856955', 'au856955', -1927],
            ['Generali Iard Sa Generali Contrat · AV027072', 'av027072', -4119],
        ], array_map(static fn (RecurringSeries $series): array => [$series->getName(), $series->getReference(), $series->getTypicalAmount()], $series));
        self::assertSame(3, $this->countAttached($series[0]));
        self::assertSame(3, $this->countAttached($series[1]));
    }

    public function testASuggestionOfTwoContractsAsOneGivesWayToOnePerContract(): void
    {
        foreach (['07', '08', '09'] as $month) {
            $this->addGenerali($month);
        }
        $this->entityManager->flush();
        // As the detection suggested it before it told contracts apart
        $whole = new RecurringSeries($this->account, $this->createCounterparty($this->user, 'generali iard sa generali contrat'), false, Periodicity::Monthly, -1927, 70, new \DateTimeImmutable('2026-09-07'));
        $this->entityManager->persist($whole);
        foreach ($this->entityManager->getRepository(Transaction::class)->findAll() as $transaction) {
            $transaction->attachTo($whole);
        }

        $series = $this->detect();

        self::assertSame(['au856955', 'av027072'], array_map(static fn (RecurringSeries $series): string => $series->getReference(), $series));
        self::assertSame(3, $this->countAttached($series[0]));
        self::assertSame(3, $this->countAttached($series[1]));
    }

    public function testASeriesOfTwoContractsTheUserConfirmedStaysOne(): void
    {
        foreach (['07', '08'] as $month) {
            $this->addGenerali($month);
        }
        $this->entityManager->flush();
        $whole = new RecurringSeries($this->account, $this->createCounterparty($this->user, 'generali iard sa generali contrat'), false, Periodicity::Monthly, -1927, 70, new \DateTimeImmutable('2026-08-07'));
        $whole->confirm('Generali', null);
        $this->entityManager->persist($whole);
        foreach ($this->entityManager->getRepository(Transaction::class)->findAll() as $transaction) {
            $transaction->attachTo($whole);
        }

        $this->addGenerali('09');
        $series = $this->detect();

        self::assertCount(1, $series);
        self::assertSame('', $series[0]->getReference());
        self::assertSame(6, $this->countAttached($series[0]));
    }

    /**
     * @return list<RecurringSeries> by name
     */
    private function detect(): array
    {
        $this->entityManager->flush();
        self::getContainer()->get(RecurrenceDetector::class)->detect($this->user);
        $this->entityManager->flush();

        return $this->entityManager->getRepository(RecurringSeries::class)->findBy([], ['name' => 'ASC']);
    }

    private function add(string $date, string $label, int $amount): void
    {
        $this->createTransaction($this->account, $date, $label, $amount);
    }

    /**
     * Two insurance contracts, debited the same day.
     */
    private function addGenerali(string $month): void
    {
        $this->add("2026-$month-07", "Prélèvement\nGENERALI IARD SA - Generali - Contrat AU856955-26{$month}20261945979\nFR7611306000474815308160605\nFR62ZZZ008982\nTIGR26{$month}2026I90777", -1927);
        $this->add("2026-$month-07", "Prélèvement\nGENERALI IARD SA - Generali - Contrat AV027072-26{$month}20261945980\nFR7611306000474815308160605\nFR62ZZZ008982\nTIGR26{$month}2026I90778", -4119);
    }

    private function findOne(string $date): Transaction
    {
        return $this->entityManager->getRepository(Transaction::class)->findOneBy(['date' => new \DateTimeImmutable($date)]) ?? throw new \LogicException('No transaction that day.');
    }

    private function countAttached(RecurringSeries $series): int
    {
        return $this->entityManager->getRepository(Transaction::class)->count(['recurringSeries' => $series]);
    }
}
