<?php

namespace App\Tests\Recurrence;

use App\Entity\BankAccount;
use App\Entity\RecurringSeries;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Periodicity;
use App\Enum\SeriesStatus;
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
        // Imported as regular, without the card statement
        foreach (['06', '07', '08', '09'] as $month) {
            $this->add("2026-$month-30", "Prélèvement carte\nDEBIT MENSUEL CARTE 497010XXXXXX1234", -8000);
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

    private function findOne(string $date): Transaction
    {
        return $this->entityManager->getRepository(Transaction::class)->findOneBy(['date' => new \DateTimeImmutable($date)]) ?? throw new \LogicException('No transaction that day.');
    }

    private function countAttached(RecurringSeries $series): int
    {
        return $this->entityManager->getRepository(Transaction::class)->count(['recurringSeries' => $series]);
    }
}
