<?php

namespace App\Tests\Statement\Import;

use App\Statement\AccountRef;
use App\Statement\Import\TransactionFingerprinter;
use App\Statement\ParsedTransaction;
use PHPUnit\Framework\TestCase;

final class TransactionFingerprinterTest extends TestCase
{
    public function testFormattingDoesNotChangeTheFingerprint(): void
    {
        $fingerprinter = new TransactionFingerprinter();

        self::assertSame(
            $fingerprinter->fingerprint($this->transaction("Prélèvement\nFREE MOBILE"), 0),
            $fingerprinter->fingerprint($this->transaction('prélèvement   Free Mobile '), 0),
        );
    }

    public function testIdenticalTransactionsAreToldApartByTheirOccurrence(): void
    {
        $fingerprinter = new TransactionFingerprinter();
        $transaction = $this->transaction('FDJ');

        self::assertNotSame($fingerprinter->fingerprint($transaction, 0), $fingerprinter->fingerprint($transaction, 1));
    }

    public function testTheAmountChangesTheFingerprint(): void
    {
        $fingerprinter = new TransactionFingerprinter();

        self::assertNotSame($fingerprinter->fingerprint($this->transaction('FDJ'), 0), $fingerprinter->fingerprint($this->transaction('FDJ', -2001), 0));
    }

    private function transaction(string $label, int $amount = -2000): ParsedTransaction
    {
        return new ParsedTransaction(new AccountRef('123', 'Compte', 'EUR'), new \DateTimeImmutable('2026-09-15'), $label, $amount, 'EUR');
    }
}
