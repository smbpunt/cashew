<?php

namespace App\Tests\Transfer;

use App\Entity\BankAccount;
use App\Entity\Category;
use App\Entity\RecurringSeries;
use App\Entity\StatementImport;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Bank;
use App\Enum\CategoryRole;
use App\Enum\Periodicity;
use App\Enum\TransactionKind;
use App\Tests\EntityFactoryTrait;
use App\Transfer\TransferDetector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TransferDetectorTest extends KernelTestCase
{
    use EntityFactoryTrait;

    private User $user;
    private BankAccount $checking;
    private BankAccount $revolut;
    private StatementImport $import;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM '.User::class)->execute();

        $this->user = $this->createUser('alice');
        $this->checking = $this->createAccount($this->user);
        $this->revolut = $this->createAccount($this->user, Bank::Revolut, 'Compte personnel (EUR)', 'Compte personnel');
        $this->import = $this->createImport('september.csv');
    }

    public function testTheTwoSidesOfMoneyMovedBetweenTheAccountsOfTheUserAreAnInternalTransfer(): void
    {
        $sent = $this->add($this->checking, '2026-09-24', "Virement émis\nVIR INST vers Bastien Modesto Al", -5000);
        $received = $this->add($this->revolut, '2026-09-25', 'Paiement envoyé par MONSIEUR NICOLAU BASTIEN', 5000);

        $this->detect();

        foreach ([$sent, $received] as $side) {
            self::assertSame(TransactionKind::InternalTransfer, $side->getKind());
            self::assertSame(CategoryRole::Transfers, $side->getCategory()?->getRole());
        }
        self::assertSame('Transfers', $sent->getCategory()?->getName());
    }

    public function testTheOtherSideMayComeFromAnEarlierImport(): void
    {
        $received = $this->add($this->revolut, '2026-08-02', 'Sur la Pocket EUR Vacances depuis EUR', 4000, $this->createImport('pocket.csv'));
        $sent = $this->add($this->checking, '2026-08-01', "Virement émis\nVIR INST vers Revolut", -4000);

        $this->detect();

        self::assertTrue($sent->isInternalTransfer());
        self::assertTrue($received->isInternalTransfer());
    }

    public function testEachTransactionIsTheOtherSideOfOneTransferOnly(): void
    {
        $sent = [
            $this->add($this->checking, '2026-07-27', "Virement émis\nVIR INST vers Bastien Modesto Al", -10000),
            $this->add($this->checking, '2026-07-27', "Virement émis\nVIR INST vers Bastien Modesto Al", -10000),
            $this->add($this->checking, '2026-07-28', "Virement émis\nVIR INST vers Bastien Modesto Al", -10000),
        ];
        $this->add($this->revolut, '2026-07-27', 'Paiement envoyé par MONSIEUR NICOLAU BASTIEN', 10000);
        $this->add($this->revolut, '2026-07-27', 'Paiement envoyé par MONSIEUR NICOLAU BASTIEN', 10000);

        $this->detect();

        self::assertSame([true, true, false], array_map(static fn (Transaction $transaction): bool => $transaction->isInternalTransfer(), $sent));
        // Sent to someone else, then
        self::assertNotNull($sent[2]->getCategory());
    }

    public function testADebitToSomeoneElseIsSpentOnATransfer(): void
    {
        $transfers = [
            $this->add($this->revolut, '2026-08-14', 'To BAPTISTE C', -3500),
            $this->add($this->revolut, '2026-08-15', 'Virement à : ANTOINE GORENE', -2000),
            $this->add($this->checking, '2026-07-11', "Virement émis\nWero vers FADIL FETISSI\n019f50ffa861703e95b5085593013210", -9000),
        ];
        // Too far apart from it to be the other side
        $this->add($this->checking, '2026-08-20', "Virement en votre faveur\nVIR INST de BAPTISTE C", 3500);

        $this->detect();

        foreach ($transfers as $transfer) {
            self::assertSame(TransactionKind::Regular, $transfer->getKind());
            self::assertSame(CategoryRole::Transfers, $transfer->getCategory()?->getRole());
        }
    }

    public function testACreditFromSomeoneElseIsLeftAlone(): void
    {
        $salary = $this->add($this->checking, '2026-09-28', "Virement en votre faveur\nVIR SEPA SALAIRE\nACME SAS", 250000);

        $this->detect();

        self::assertNull($salary->getCategory());
        self::assertSame(TransactionKind::Regular, $salary->getKind());
    }

    public function testTheSameAmountByChanceIsNotATransfer(): void
    {
        $purchase = $this->add($this->checking, '2026-09-10', 'CARREFOUR MIRAMAS', -5000);
        $refund = $this->add($this->revolut, '2026-09-11', 'Amazon', 5000);

        $this->detect();

        self::assertNull($purchase->getCategory());
        self::assertNull($refund->getCategory());
        self::assertFalse($purchase->isInternalTransfer());
    }

    public function testWhatTheUserCategorizedIsLeftAlone(): void
    {
        $rent = $this->createCategory($this->user, 'Rent');
        $sent = $this->add($this->checking, '2026-09-01', "Virement émis\nWEB Loyer Septembre", -80000);
        $sent->categorize($rent);
        $received = $this->add($this->revolut, '2026-09-01', 'Paiement envoyé par MONSIEUR NICOLAU BASTIEN', 80000);

        $this->detect();

        self::assertSame($rent, $sent->getCategory());
        self::assertFalse($sent->isInternalTransfer());
        self::assertNull($received->getCategory());
    }

    public function testTheCategoryOfItsSeriesOverridesTransfers(): void
    {
        $rent = $this->createCategory($this->user, 'Rent');
        $series = new RecurringSeries($this->checking, 'web loyer', false, 'Rent', Periodicity::Monthly, -80000, 90, new \DateTimeImmutable('2026-08-01'));
        $series->confirm('Rent', $rent);
        $this->entityManager->persist($series);
        $sent = $this->add($this->checking, '2026-09-01', "Virement émis\nWEB Loyer", -80000);
        $sent->attachTo($series);

        $this->detect();

        self::assertNull($sent->getCategory());
        self::assertSame($rent, $sent->getEffectiveCategory());
    }

    public function testFilesThemInTheTransfersCategoryOfTheUser(): void
    {
        $mine = $this->createCategory($this->user, 'Transfers');
        $this->add($this->revolut, '2026-08-14', 'To BAPTISTE C', -3500);

        $this->detect();

        self::assertSame(CategoryRole::Transfers, $mine->getRole());
        self::assertSame(1, $this->entityManager->getRepository(Category::class)->count(['owner' => $this->user]));
    }

    private function detect(): void
    {
        $this->entityManager->flush();
        self::getContainer()->get(TransferDetector::class)->detect($this->import);
        $this->entityManager->flush();
    }

    private function createImport(string $filename): StatementImport
    {
        $import = new StatementImport($this->user, $filename, null, hash('sha256', $filename), new \DateTimeImmutable());
        $this->entityManager->persist($import);

        return $import;
    }

    private function add(BankAccount $account, string $date, string $label, int $amount, ?StatementImport $import = null): Transaction
    {
        return $this->createTransaction($account, $date, $label, $amount, import: $import ?? $this->import);
    }
}
