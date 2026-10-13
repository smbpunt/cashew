<?php

namespace App\Tests\Transfer;

use App\Entity\BankAccount;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Bank;
use App\Enum\CategoryRole;
use App\Enum\TransactionKind;
use App\Transfer\TransferFiling;
use PHPUnit\Framework\TestCase;

final class TransferFilingTest extends TestCase
{
    private User $user;
    private Category $transfers;

    protected function setUp(): void
    {
        $this->user = new User();
        $this->transfers = new Category($this->user)->setName('Transfers')->setRole(CategoryRole::Transfers);
    }

    public function testFilesWhatTheUserToldNothingOf(): void
    {
        $transaction = $this->transaction();

        new TransferFiling()->file($transaction, $this->transfers);

        self::assertSame($this->transfers, $transaction->getCategory());
    }

    public function testLeavesAloneWhatTheUserCategorized(): void
    {
        $rent = new Category($this->user)->setName('Rent');
        $transaction = $this->transaction();
        $transaction->categorize($rent);

        new TransferFiling()->file($transaction, $this->transfers);

        self::assertSame($rent, $transaction->getCategory());
    }

    public function testReleasesThoseFiledInTransfers(): void
    {
        $filed = $this->transaction();
        $filed->categorize($this->transfers);
        $rent = new Category($this->user)->setName('Rent');
        $categorized = $this->transaction();
        $categorized->categorize($rent);

        new TransferFiling()->release([$filed, $categorized]);

        self::assertNull($filed->getCategory());
        self::assertSame($rent, $categorized->getCategory());
    }

    public function testKeepsMoneyMovedBetweenTheAccountsOfTheUserInTransfers(): void
    {
        $internal = $this->transaction(TransactionKind::InternalTransfer);
        $internal->categorize($this->transfers);

        new TransferFiling()->release([$internal]);

        self::assertSame($this->transfers, $internal->getCategory());
    }

    private function transaction(TransactionKind $kind = TransactionKind::Regular): Transaction
    {
        $account = new BankAccount($this->user, Bank::CreditAgricole, '12345678901', 'Compte de Dépôt', 'EUR');

        return new Transaction($account, null, new \DateTimeImmutable('2026-09-01'), "Virement émis\nLOYER", null, -80000, 'EUR', $kind, null, 'fingerprint');
    }
}
