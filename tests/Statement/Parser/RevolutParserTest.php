<?php

namespace App\Tests\Statement\Parser;

use App\Statement\Exception\UnreadableStatementException;
use App\Statement\ParsedTransaction;
use App\Statement\Parser\RevolutParser;
use App\Statement\Reader\CsvReader;
use App\Statement\Reader\FrenchDateParser;
use App\Statement\Reader\MoneyParser;
use PHPUnit\Framework\TestCase;

final class RevolutParserTest extends TestCase
{
    public function testParse(): void
    {
        $transactions = $this->parse(__DIR__.'/../../Fixtures/Statement/revolut.csv');

        self::assertSame([
            ['Compte personnel (EUR)', '2025-06-09', 'Recharge sur Apple Pay via *1234', 150000, 'EUR', 'Rechargez'],
            ['Compte personnel (EUR)', '2025-07-12', 'FDJ', -2000, 'EUR', 'Commerçant'],
            ['Compte personnel (EUR)', '2025-07-12', 'FDJ', -2000, 'EUR', 'Commerçant'],
            ['Compte personnel (EUR)', '2026-02-03', 'Virement de : JANE DOE', 3000, 'EUR', 'Autres'],
            ['Compte personnel (EUR)', '2026-08-14', 'Frais de livraison de carte', -500, 'EUR', 'Frais'],
            // In the account's currency, not in the euros of the second amount column
            ['Compte personnel (AUD)', '2025-07-20', 'Virement de : JOHN SMITH', 5000, 'AUD', 'Autres'],
            ['Compte personnel (AUD)', '2025-07-20', 'Change en EUR', -5000, 'AUD', 'Changes'],
            ['Vacances (EUR)', '2026-08-01', 'Transfert depuis Compte personnel', 10000, 'EUR', null],
        ], array_map(static fn (ParsedTransaction $transaction): array => [
            $transaction->account->externalId,
            $transaction->date->format('Y-m-d'),
            $transaction->label,
            $transaction->amount,
            $transaction->currency,
            $transaction->bankCategory,
        ], $transactions));

        self::assertSame('Vacances', $transactions[7]->account->label);
        self::assertSame('AUD', $transactions[5]->account->currency);
    }

    public function testAnAmountInAnotherCurrencyThanItsAccountIsUnreadable(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'statement');
        file_put_contents($file, implode("\n", [
            '"Comptes courants Relevés de transactions",,,,,,,',
            '"Compte personnel (EUR)",,,,,,,',
            'Date,Description,Catégorie,"Argent entrant/sortant",Solde,"Impôt retenu","Autres taxes",Frais',
            '"9 juin 2025",FDJ,Commerçant,"-20,00AU$","0,00€","0,00€","0,00€","0,00€"',
        ]));

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
        $parser = new RevolutParser(new CsvReader(), new MoneyParser(), new FrenchDateParser());

        return [...$parser->parse(new \SplFileInfo($path))];
    }
}
