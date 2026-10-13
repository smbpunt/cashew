<?php

namespace App\Tests\Recurrence;

use App\Entity\BankAccount;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\Bank;
use App\Enum\TransactionKind;
use App\Recurrence\CounterpartyNormalizer;
use App\Recurrence\ReferenceSplitter;
use PHPUnit\Framework\TestCase;

final class ReferenceSplitterTest extends TestCase
{
    private BankAccount $account;

    protected function setUp(): void
    {
        $this->account = new BankAccount(new User()->setLogin('alice'), Bank::CreditAgricole, '123', 'Compte de Dépôt', 'EUR');
    }

    public function testTellsApartTwoContractsGoingOnAtTheSameTime(): void
    {
        $transactions = [];
        foreach (['07', '08', '09'] as $i => $month) {
            $transactions[] = $this->generali("2026-$month-05", 'AU856955', "26{$month}20261945979", 'I9077'.$i, -1927);
            $transactions[] = $this->generali("2026-$month-05", 'AV027072', "26{$month}20261945980", 'I9078'.$i, -8241);
        }
        // From the same insurer, without a contract
        $transactions[] = $this->transaction('2026-08-20', "Prélèvement\nGENERALI IARD SA - Generali - Frais", -500);

        $contracts = $this->split($transactions);

        self::assertSame(['au856955', 'av027072', ''], array_keys($contracts));
        self::assertSame([-1927, -1927, -1927], array_map(static fn (Transaction $transaction): int => $transaction->getAmount(), $contracts['au856955']));
        self::assertSame([-8241, -8241, -8241], array_map(static fn (Transaction $transaction): int => $transaction->getAmount(), $contracts['av027072']));
        self::assertCount(1, $contracts['']);
    }

    public function testAReferenceOnEveryTransactionIsNotAContract(): void
    {
        $transactions = [];
        foreach (['07', '08', '09'] as $month) {
            $transactions[] = $this->transaction("2026-$month-10", "Prélèvement\nPACIFICA - ECHEANCE $month/2026 COTISATION DU CONTRAT PACIFICA 11476933908\nFR29ZZZ311731\n0006589216{$month}908", -3500);
        }

        self::assertSame([''], array_keys($this->split($transactions)));
    }

    public function testAMandateThatReplacesAnotherIsTheSameSeries(): void
    {
        $transactions = [];
        foreach (['03', '04', '05'] as $month) {
            $transactions[] = $this->transaction("2026-$month-15", "Prélèvement\nFREE MOBILE\nMDT/FM445566", -1999);
        }
        foreach (['06', '07', '08'] as $month) {
            $transactions[] = $this->transaction("2026-$month-15", "Prélèvement\nFREE MOBILE\nMDT/FM778899", -1999);
        }

        self::assertSame([''], array_keys($this->split($transactions)));
    }

    public function testReferencesThatChangeEveryTimeAreNotContracts(): void
    {
        $transactions = [];
        foreach (['07', '08', '09'] as $month) {
            $transactions[] = $this->transaction("2026-$month-15", "Prélèvement\nFREE MOBILE\nREF/{$month}0042 ECH/15{$month}26", -1999);
            $transactions[] = $this->transaction("2026-$month-15", "Prélèvement\nFREE MOBILE\nREF/{$month}0043 ECH/15{$month}26", -999);
        }

        self::assertSame([''], array_keys($this->split($transactions)));
    }

    /**
     * @param list<Transaction> $transactions
     *
     * @return array<string, list<Transaction>>
     */
    private function split(array $transactions): array
    {
        return new ReferenceSplitter(new CounterpartyNormalizer())->split($transactions);
    }

    private function generali(string $date, string $contract, string $occurrence, string $reference, int $amount): Transaction
    {
        return $this->transaction($date, "Prélèvement\nGENERALI IARD SA - Generali - Contrat $contract-$occurrence\nFR7611306000474815308160605\nFR62ZZZ008982\nTIGR26082026$reference", $amount);
    }

    private function transaction(string $date, string $label, int $amount): Transaction
    {
        return new Transaction($this->account, null, new \DateTimeImmutable($date), $label, $amount, 'EUR', TransactionKind::Regular, null, bin2hex(random_bytes(32)));
    }
}
