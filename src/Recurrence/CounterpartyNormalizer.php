<?php

namespace App\Recurrence;

/**
 * Tells who's on the other side of a transaction, from its label, the same way from one occurrence to the next:
 * "Prélèvement\nFREE MOBILE" and "PRLV SEPA FREE MOBILE 12/09" are both "free mobile".
 */
final class CounterpartyNormalizer
{
    /**
     * Words that start a label to say how the money moved, rather than with whom.
     */
    private const array OPERATION_WORDS = [
        'a', 'achat', 'au', 'avoir', 'carte', 'cb', 'cheque', 'de', 'distributeur', 'du', 'emis', 'en', 'faveur', 'inst',
        'instantane', 'paiement', 'par', 'prelevement', 'prlv', 'recu', 'remise', 'retrait', 'sepa', 'vers', 'vir',
        'virement', 'votre',
    ];

    /**
     * Words that announce the references: "ECH/050926 ID EMETTEUR/FR45ZZZ654321 MDT/EDF778899 REF/37781".
     */
    private const array REFERENCE_MARKERS = ['ech', 'emetteur', 'ics', 'id', 'libelle', 'mdt', 'ref', 'rum'];

    /**
     * Months, in French and in English, in full or not: "SALAIRE SEPTEMBRE", "LOYER OCT".
     */
    private const array MONTHS = [
        'janvier', 'janv', 'fevrier', 'fevr', 'fev', 'mars', 'avril', 'avr', 'mai', 'juin', 'juillet', 'juil', 'aout',
        'septembre', 'sept', 'octobre', 'novembre', 'decembre',
        'january', 'jan', 'february', 'feb', 'march', 'mar', 'april', 'apr', 'may', 'june', 'jun', 'july', 'jul',
        'august', 'aug', 'september', 'sep', 'october', 'oct', 'november', 'nov', 'december', 'dec',
    ];

    private static ?\Transliterator $transliterator = null;

    /**
     * @return string empty when the label only holds references
     */
    public function normalize(string $label): string
    {
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $label)), static fn (string $line): bool => '' !== $line));

        // A label on several lines (Crédit Agricole) may start with the kind of operation: "Prélèvement", "Virement en votre faveur"...
        if (\count($lines) > 1 && array_all(self::tokens($lines[0]), static fn (string $token): bool => \in_array($token, self::OPERATION_WORDS, true))) {
            array_shift($lines);
        }

        // Who it is comes first; then come the references: mandate, creditor, due date...
        $tokens = [];
        foreach ($lines as $i => $line) {
            $words = [];
            $hasReferences = $ended = false;
            foreach (self::tokens($line) as $token) {
                if (\in_array($token, self::REFERENCE_MARKERS, true) && [] !== [...$tokens, ...$words]) {
                    $ended = true;
                    break;
                }

                if (self::isReference($token)) {
                    $hasReferences = true;
                } else {
                    $words[] = $token;
                }
            }

            if ($i > 0 && ($hasReferences || [] === $words)) {
                break;
            }
            $tokens = [...$tokens, ...$words];
            if ($ended) {
                break;
            }
        }

        $words = $tokens;
        while ([] !== $words && \in_array($words[0], self::OPERATION_WORDS, true)) {
            array_shift($words);
        }

        // Nothing but the kind of operation: then it's what tells the transactions apart
        return mb_substr(implode(' ', [] === $words ? $tokens : $words), 0, 255);
    }

    /**
     * A name to show for the counterparty, until the user gives a better one.
     */
    public function name(string $counterparty): string
    {
        return ucwords($counterparty);
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $line): array
    {
        self::$transliterator ??= \Transliterator::create('Any-Latin; Latin-ASCII; Lower()') ?? throw new \LogicException('The transliterator is unavailable.');

        return preg_split('/[^a-z0-9]+/', (string) self::$transliterator->transliterate($line), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Dates, months, amounts, card numbers, mandate and creditor references: they change from one occurrence to the
     * next. A name may hold a digit, not two: "O2switch", "M6".
     */
    private static function isReference(string $token): bool
    {
        return ctype_digit($token) || preg_match_all('/\d/', $token) >= 2 || \in_array($token, self::MONTHS, true);
    }
}
