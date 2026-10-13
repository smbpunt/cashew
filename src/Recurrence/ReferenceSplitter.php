<?php

namespace App\Recurrence;

use App\Entity\Transaction;

/**
 * Tells apart the contracts of a counterparty, from the references of their labels: two insurance contracts debited on
 * the same day, "Contrat AU856955-…" and "Contrat AV027072-…", are two series, not one.
 *
 * A reference tells a contract when it comes back from one occurrence to the next, yet isn't on every transaction of
 * the counterparty, as its creditor id is; and only when another one goes on at the same time: a mandate that replaces
 * another one, months later, is still the same series.
 */
final readonly class ReferenceSplitter
{
    public function __construct(
        private CounterpartyNormalizer $normalizer,
    ) {
    }

    /**
     * @param list<Transaction> $transactions of the same account, counterparty and direction, oldest first
     *
     * @return array<string, list<Transaction>> by the reference of their contract, oldest first: all of them under ""
     *                                          when there's a single one, and under "" too, those of none
     */
    public function split(array $transactions): array
    {
        /** @var list<list<string>> $references by transaction */
        $references = [];
        /** @var array<string, list<int>> $holders the transactions of each reference, in the order of the labels */
        $holders = [];
        /** @var array<string, array<string, true>> $days */
        $days = [];
        foreach ($transactions as $i => $transaction) {
            $references[$i] = $this->normalizer->references($transaction->getLabel());
            foreach ($references[$i] as $reference) {
                $holders[$reference][] = $i;
                $days[$reference][$transaction->getDate()->format('Y-m-d')] = true;
            }
        }

        /** @var array<string, string> $candidates by the transactions that hold them: of two references that always go together, the first one */
        $candidates = [];
        foreach ($holders as $reference => $indexes) {
            // Every time different, or on every transaction: it doesn't tell a contract
            if (\count($days[(string) $reference]) < 2 || \count($indexes) === \count($transactions)) {
                continue;
            }
            $candidates[implode(',', $indexes)] ??= (string) $reference;
        }

        $contracts = array_values(array_filter($candidates, static fn (string $reference): bool => array_any(
            $candidates,
            static fn (string $other): bool => $other !== $reference && self::goOnTogether($days[$reference], $days[$other], $holders[$reference], $holders[$other]),
        )));
        if (\count($contracts) < 2) {
            return ['' => $transactions];
        }

        $groups = [];
        foreach ($transactions as $i => $transaction) {
            $groups[array_find($references[$i], static fn (string $reference): bool => \in_array($reference, $contracts, true)) ?? ''][] = $transaction;
        }

        return $groups;
    }

    /**
     * Whether two references are of two contracts at the same time: never on the same transaction, and from the first
     * day to the last, one goes on while the other does.
     *
     * @param array<string, true> $days
     * @param array<string, true> $otherDays
     * @param list<int>           $holders
     * @param list<int>           $otherHolders
     */
    private static function goOnTogether(array $days, array $otherDays, array $holders, array $otherHolders): bool
    {
        return [] === array_intersect($holders, $otherHolders)
            && min(array_keys($days)) <= max(array_keys($otherDays))
            && min(array_keys($otherDays)) <= max(array_keys($days));
    }
}
