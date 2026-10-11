<?php

namespace App\Tests\Recurrence;

use App\Recurrence\CounterpartyNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CounterpartyNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function labels(): iterable
    {
        yield 'a direct debit' => ["Prélèvement\nFREE MOBILE   \n", 'free mobile'];
        yield 'a direct debit and its references' => ["Prélèvement\nEDF CLIENTS PARTICULIERS\nECH/050926 ID EMETTEUR/FR45ZZZ654321 MDT/EDF778899 REF/37781", 'edf clients particuliers'];
        yield 'a loan instalment and its rank' => ["Prélèvement\nECHEANCE PRET\n00012345678 ECH 17/240", 'echeance pret'];
        yield 'a transfer on several lines' => ["Virement en votre faveur\nVIR SEPA SALAIRE\nACME SAS\n\nFR00ZZZ000000", 'salaire acme sas'];
        yield 'a card payment and its date' => ["Paiement par carte\nCARREFOUR MARSEILLE 09/09", 'carrefour marseille'];
        yield 'a card statement line' => ['CARREFOUR MARSEILLE ', 'carrefour marseille'];
        yield 'accents and punctuation' => ['Virement de : Hélène Dupré', 'helene dupre'];
        yield 'a short name with a digit' => ['M6 Plus', 'm6 plus'];
        yield 'only the kind of operation' => ['Frais de livraison de carte', 'frais de livraison de carte'];
        yield 'only references' => ['12/09 0042', ''];
        yield 'the month of a salary' => ["Virement en votre faveur\nVIR SEPA SALAIRE SEPTEMBRE 2026\nACME SAS", 'salaire acme sas'];
        yield 'a month, abbreviated' => ['LOYER OCT 2026', 'loyer'];
        yield 'a month, in English' => ['Salary March', 'salary'];
        yield 'a first line that is not the kind of operation' => ["Remboursement\nAMAZON", 'remboursement amazon'];
        yield 'a cash withdrawal' => ["Retrait au distributeur\nCA MARSEILLE PRADO 09/09", 'ca marseille prado'];
        yield 'a cheque deposit' => ["Remise de chèque\nM DUPONT", 'm dupont'];
        yield 'references on the same line' => ['PRLV SEPA FREE MOBILE ECH/150926 ID EMETTEUR/FR12ZZZ123456 MDT/++FM3A88 REF/090042', 'free mobile'];
        yield 'references without digits' => ["Prélèvement\nCANAL PLUS\nLIBELLE ABONNEMENT RUM ICS", 'canal plus'];
        yield 'a name with one digit' => ["Paiement par carte\nO2SWITCH", 'o2switch'];
        yield 'a reference with letters' => ["Prélèvement\nEDF778899 FACTURE", 'facture'];
    }

    #[DataProvider('labels')]
    public function testTellsTheCounterpartyFromTheLabel(string $label, string $counterparty): void
    {
        self::assertSame($counterparty, new CounterpartyNormalizer()->normalize($label));
    }

    public function testTheReferencesOfEachOccurrenceDoNotChangeIt(): void
    {
        $normalizer = new CounterpartyNormalizer();

        self::assertSame(
            $normalizer->normalize("Prélèvement\nFREE MOBILE\nECH/150826 ID EMETTEUR/FR12ZZZ123456 MDT/++FM2A88 REF/080042"),
            $normalizer->normalize("Prélèvement\nFREE MOBILE\nECH/150926 ID EMETTEUR/FR12ZZZ123456 MDT/++FM3A88 REF/090042"),
        );
    }

    public function testNamesTheCounterparty(): void
    {
        self::assertSame('Salaire Acme Sas', new CounterpartyNormalizer()->name('salaire acme sas'));
    }
}
