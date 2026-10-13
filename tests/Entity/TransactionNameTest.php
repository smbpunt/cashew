<?php

namespace App\Tests\Entity;

use App\Entity\BankAccount;
use App\Entity\Category;
use App\Entity\Counterparty;
use App\Entity\RecurringSeries;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Bank;
use App\Enum\Periodicity;
use App\Enum\TransactionKind;
use PHPUnit\Framework\TestCase;

/**
 * What the user knows a transaction as: the name of its series, or else that of its counterparty, or else its label;
 * and the category it counts in.
 */
final class TransactionNameTest extends TestCase
{
    private BankAccount $account;
    private Counterparty $amazon;

    protected function setUp(): void
    {
        $owner = new User();
        $this->account = new BankAccount($owner, Bank::CreditAgricole, '123', 'Compte de Dépôt', 'EUR');
        $this->amazon = new Counterparty($owner, 'amazon');
    }

    public function testAnUnknownTransactionGoesByItsLabel(): void
    {
        $transaction = $this->transaction();

        self::assertSame('Paiement par carte · AMAZON', $transaction->getName());
        self::assertFalse($transaction->isIdentified());
    }

    public function testATransactionGoesByTheNameOfItsCounterparty(): void
    {
        $transaction = $this->transaction();
        $this->amazon->rename('  Amazon  ');

        self::assertSame('Amazon', $transaction->getName());
        self::assertTrue($transaction->isIdentified());

        // A blank name takes it back
        $this->amazon->rename(' ');
        self::assertSame('Paiement par carte · AMAZON', $transaction->getName());
        self::assertFalse($transaction->isIdentified());
    }

    public function testATransactionOfAConfirmedSeriesGoesByTheNameOfTheSeries(): void
    {
        $this->amazon->rename('Amazon');
        $series = $this->series();
        $transaction = $this->transaction();
        $transaction->attachTo($series);

        // Only suggested: not one yet
        self::assertSame('Amazon', $transaction->getName());

        $series->confirm('Prime', null);
        self::assertSame('Prime', $transaction->getName());
        self::assertTrue($transaction->isIdentified());
    }

    public function testASeriesNamedAsItsCounterpartyFollowsIt(): void
    {
        $series = $this->series();
        self::assertSame('Amazon', $series->getName());

        $series->confirm('Amazon', null);
        $this->amazon->rename('Amazon.fr');
        self::assertSame('Amazon.fr', $series->getName());

        $series->rename('Prime');
        $this->amazon->rename('Amazon');
        self::assertSame('Prime', $series->getName());
    }

    public function testASeriesOfOneOfTheContractsOfItsCounterpartyGoesByItsReference(): void
    {
        $series = $this->series('au856955');

        self::assertSame('Amazon · AU856955', $series->getName());
        $this->amazon->rename('Generali');
        self::assertSame('Generali · AU856955', $series->getName());
    }

    public function testATransactionFiledInACategoryIsIdentifiedThoughUnnamed(): void
    {
        $transaction = $this->transaction();
        $shopping = new Category(new User())->setName('Shopping');

        $this->amazon->categorize($shopping);
        self::assertSame($shopping, $transaction->getEffectiveCategory());
        self::assertTrue($transaction->isIdentified());
        self::assertFalse($transaction->isNamed());
        self::assertSame('Paiement par carte · AMAZON', $transaction->getName());

        $this->amazon->categorize(null);
        self::assertFalse($transaction->isIdentified());
        $transaction->categorize($shopping);
        self::assertTrue($transaction->isIdentified());
    }

    public function testATransactionTakesTheCategoryOfItsSeriesOverThatOfItsCounterparty(): void
    {
        $shopping = new Category(new User())->setName('Shopping');
        $subscriptions = new Category(new User())->setName('Subscriptions');
        $this->amazon->categorize($shopping);
        $series = $this->series();
        $transaction = $this->transaction();
        $transaction->attachTo($series);

        // Of no category: that of its counterparty
        $series->confirm('Prime', null);
        self::assertSame($shopping, $transaction->getEffectiveCategory());

        $series->confirm('Prime', $subscriptions);
        self::assertSame($subscriptions, $transaction->getEffectiveCategory());
    }

    private function transaction(): Transaction
    {
        return new Transaction($this->account, null, new \DateTimeImmutable('2026-09-05'), "Paiement par carte\nAMAZON", $this->amazon, -699, 'EUR', TransactionKind::Regular, null, 'fingerprint');
    }

    private function series(string $reference = ''): RecurringSeries
    {
        return new RecurringSeries($this->account, $this->amazon, false, Periodicity::Monthly, -699, 90, new \DateTimeImmutable('2026-09-05'), $reference);
    }
}
