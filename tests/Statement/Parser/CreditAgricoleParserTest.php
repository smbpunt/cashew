<?php

namespace App\Tests\Statement\Parser;

use App\Enum\TransactionKind;
use App\Statement\Exception\UnreadableStatementException;
use App\Statement\ParsedTransaction;
use App\Statement\Parser\CreditAgricoleParser;
use App\Statement\Reader\CsvReader;
use App\Statement\Reader\FrenchDateParser;
use App\Statement\Reader\MoneyParser;
use PHPUnit\Framework\TestCase;

final class CreditAgricoleParserTest extends TestCase
{
    public function testParse(): void
    {
        $transactions = $this->parse(__DIR__.'/../../Fixtures/Statement/credit_agricole.csv');

        self::assertSame([
            ['2026-09-30', "Prélèvement carte\nDEBIT MENSUEL CARTE 497010XXXXXX1234", -6240, TransactionKind::DeferredCardSettlement],
            ['2026-09-28', "Virement en votre faveur\nVIR SEPA SALAIRE\nACME SAS\nFR00ZZZ000000", 250000, TransactionKind::Regular],
            ['2026-09-15', "Prélèvement\nFREE MOBILE", -1999, TransactionKind::Regular],
            ['2026-09-15', "Prélèvement\nFREE MOBILE", -1999, TransactionKind::Regular],
            // No card statement in the export for this settlement: its purchases aren't imported, it is
            ['2026-08-31', "Prélèvement carte\nDEBIT MENSUEL CARTE 497010XXXXXX1234", -8000, TransactionKind::CardSettlement],
            ['2026-09-25', 'CARREFOUR MARSEILLE', -5000, TransactionKind::DeferredCardPurchase],
            ['2026-09-02', 'BOULANGERIE DU PORT', -1240, TransactionKind::DeferredCardPurchase],
        ], array_map(static fn (ParsedTransaction $transaction): array => [
            $transaction->date->format('Y-m-d'),
            $transaction->label,
            $transaction->amount,
            $transaction->kind,
        ], $transactions));

        foreach ($transactions as $transaction) {
            self::assertSame('12345678901', $transaction->account->externalId);
            self::assertSame('Compte de Dépôt carte', $transaction->account->label);
            self::assertSame('EUR', $transaction->currency);
            self::assertNull($transaction->bankCategory);
        }
    }

    public function testTransactionsBeforeAnyAccountAreUnreadable(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'statement');
        file_put_contents($file, "Date;Libellé;Débit euros;Crédit euros;\n30/09/2026;CARREFOUR;50,00;;\n");

        try {
            $this->expectException(UnreadableStatementException::class);
            $this->parse($file);
        } finally {
            unlink($file);
        }
    }

    /**
     * @return list<ParsedTransaction>
     */
    private function parse(string $path): array
    {
        $parser = new CreditAgricoleParser(new CsvReader(), new MoneyParser(), new FrenchDateParser());

        return [...$parser->parse(new \SplFileInfo($path))];
    }
}
