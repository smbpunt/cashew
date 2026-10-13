<?php

namespace App\Tests\Counterparty;

use App\Counterparty\CounterpartyNormalizer;
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
        yield 'accents and punctuation' => ['Virement de : Hélène Dupré', 'dupre helene'];
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

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function transfers(): iterable
    {
        yield 'whatever the motive, the civility, the order of the names, the direction' => [[
            "Virement en votre faveur\nVIR INST de MME NICOLAU NADEGE - billet d avion",
            "Virement en votre faveur\nVIR INST de MME NICOLAU NADEGE - cadeau romain",
            "Virement émis\nVIR INST vers Nadege NICOLAU",
            "Virement émis\nWEB Nadege NICOLAU - MONSIEUR NICOLAU BASTIEN",
        ], 'nadege nicolau'];
        yield 'from a bank to the other' => [[
            'Virement de : ANTOINE GORENE',
            'Virement à : ANTOINE GORENE',
            "Virement émis\nVIR INST vers GORENE Antoine",
            "Virement en votre faveur\nVIR INST de Antoine GORENE - Virement de Antoine GORENE",
        ], 'antoine gorene'];
        yield 'by Wero' => [[
            "Virement en votre faveur\nVIR INST WERO de MME MORO MARINA\n019f1dfd8e5843beaed96de7aa3adb",
            "Virement émis\nWero vers MARINA MORO\n019f50ffa861703e95b5085593013210",
        ], 'marina moro'];
        yield 'on Revolut' => [[
            'To Tristan Aviles',
            'Paiement envoyé par MR  TRISTAN AVILES',
            "Virement en votre faveur\nVIR INST de MR TRISTAN AVILES - VIREMENT DE MR TRISTAN AVILES\nVIREMENT DE MR TRISTAN AVILES",
        ], 'aviles tristan'];
        yield 'on Crédit Agricole, the old way' => [[
            "Virement en votre faveur\nDE M.       MORO ANTHONY",
            "Virement émis\nWEB ANTHONY MORO",
        ], 'anthony moro'];
    }

    /**
     * @param list<string> $labels
     */
    #[DataProvider('transfers')]
    public function testTellsWhoATransferIsToOrFrom(array $labels, string $counterparty): void
    {
        $normalizer = new CounterpartyNormalizer();

        self::assertSame(array_fill(0, \count($labels), $counterparty), array_map($normalizer->normalize(...), $labels));
    }

    public function testATransferFromACompanyIsNotOfAPerson(): void
    {
        $normalizer = new CounterpartyNormalizer();

        // Neither "VIR SEPA", nor a label starting with "de" or "web" on its own
        self::assertSame('salaire acme sas', $normalizer->normalize("Virement en votre faveur\nVIR SEPA SALAIRE\nACME SAS"));
        self::assertSame('web loyer', $normalizer->normalize('WEB LOYER'));
        self::assertSame('gaulle charles', $normalizer->normalize('DE GAULLE CHARLES'));
        self::assertSame('webhelp', $normalizer->normalize('WEBHELP'));
    }

    public function testTheReferencesOfEachOccurrenceDoNotChangeIt(): void
    {
        $normalizer = new CounterpartyNormalizer();

        self::assertSame(
            $normalizer->normalize("Prélèvement\nFREE MOBILE\nECH/150826 ID EMETTEUR/FR12ZZZ123456 MDT/++FM2A88 REF/080042"),
            $normalizer->normalize("Prélèvement\nFREE MOBILE\nECH/150926 ID EMETTEUR/FR12ZZZ123456 MDT/++FM3A88 REF/090042"),
        );
    }

    public function testTellsTheReferencesOfTheWholeLabel(): void
    {
        self::assertSame(
            ['au856955', '260820261945979', 'fr7611306000474815308160605', 'fr62zzz008982', 'tigr26082026i90777'],
            new CounterpartyNormalizer()->references("Prélèvement\nGENERALI IARD SA - Generali - Contrat AU856955-260820261945979\nFR7611306000474815308160605\nFR62ZZZ008982\nTIGR26082026I90777"),
        );
        // Each once
        self::assertSame(
            ['518397105', '260820260477767', 'tigr1130648153081606', 'fr22zzz102628'],
            new CounterpartyNormalizer()->references("Prélèvement\nGENERALI VIE SA - Generali - Contrat 518397105-260820260477767\nTIGR1130648153081606---518397105\nFR22ZZZ102628"),
        );
    }

    public function testDatesTimesAndShortNumbersAreNotReferences(): void
    {
        self::assertSame([], new CounterpartyNormalizer()->references("Retrait au distributeur\nMIRAMAS EXT 17/07 16H11 ECHEANCE 11/2025 X7167 O2SWITCH"));
    }

    public function testTellsTheWordsOfTheLabel(): void
    {
        self::assertSame('virement a helene dupre', new CounterpartyNormalizer()->words('Virement à : Hélène Dupré'));
        self::assertSame('virement emis vir inst vers dylan t', new CounterpartyNormalizer()->words("Virement émis\nVIR INST vers DYLAN T"));
    }
}
