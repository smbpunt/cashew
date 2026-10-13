<?php

namespace App\Transfer;

use App\Category\TransferCategory;
use App\Entity\Category;
use App\Entity\StatementImport;
use App\Entity\Transaction;
use App\Enum\TransactionKind;
use App\Recurrence\CounterpartyNormalizer;
use App\Repository\TransactionRepository;

/**
 * Files the transfers an import brought in the user's "Transfers" category.
 *
 * A debit and a credit of the same amount, between two of the user's accounts and a few days apart, are the two sides
 * of money moved from one to the other: both leave the totals. A debit worded as a transfer, and that no account of
 * the user received, went to someone else: it's spent. A credit worded as a transfer, a salary or a refund, is left
 * alone. So is a transaction the user categorized already.
 */
final readonly class TransferDetector
{
    /**
     * Up to how many days a transfer may take from one account to the other.
     */
    private const int MAX_DAYS = 3;

    /**
     * How banks word a transfer, in the label's lowercase words: "Virement émis", "VIR INST vers…", "Wero vers…",
     * "Virement à : …" and "To …" (Revolut), "Paiement envoyé par…", "Sur la Pocket EUR Vacances depuis EUR",
     * "Transfert depuis Compte personnel"...
     */
    private const array PATTERNS = [
        '/^virement\b/',
        '/\bvir (inst|sepa)\b/',
        '/\bwero\b/',
        '/^to /',
        '/^transfer (to|from)\b/',
        '/^transfert (depuis|vers)\b/',
        '/^paiement envoye par\b/',
        '/^(sur la|retrait depuis une|depuis la) pocket\b/',
        '/^pocket withdrawal\b/',
    ];

    public function __construct(
        private TransactionRepository $transactions,
        private TransferCategory $transferCategory,
        private CounterpartyNormalizer $normalizer,
    ) {
    }

    /**
     * Without flushing: the transactions of the import must be flushed already.
     */
    public function detect(StatementImport $import): void
    {
        $imported = $this->transactions->findBy(['statementImport' => $import, 'kind' => TransactionKind::Regular], ['date' => 'ASC', 'id' => 'ASC']);
        if ([] === $imported) {
            return;
        }

        $owner = $import->getOwner();
        $category = $this->transferCategory->of($owner);

        $dates = array_map(static fn (Transaction $transaction): \DateTimeImmutable => $transaction->getDate(), $imported);
        /** @var array<string, list<Transaction>> $byAmount the transactions of the period, imported before or not, by currency and amount */
        $byAmount = [];
        $limit = \sprintf('%d days', self::MAX_DAYS);
        foreach ($this->transactions->findForTransfers($owner, min($dates)->modify('-'.$limit), max($dates)->modify('+'.$limit)) as $transaction) {
            $byAmount[$transaction->getCurrency().$transaction->getAmount()][] = $transaction;
        }

        /** @var array<int, true> $paired by object id */
        $paired = [];
        foreach ($imported as $transaction) {
            if (isset($paired[spl_object_id($transaction)]) || null !== $transaction->getCategory() || 0 === $transaction->getAmount()) {
                continue;
            }

            $other = $this->otherSideOf($transaction, $byAmount[$transaction->getCurrency().(-$transaction->getAmount())] ?? [], $paired);
            if (null !== $other) {
                foreach ([$transaction, $other] as $side) {
                    $paired[spl_object_id($side)] = true;
                    $side->markAsInternalTransfer();
                    self::file($side, $category);
                }
            } elseif ($transaction->getAmount() < 0 && $this->isWordedAsTransfer($transaction)) {
                self::file($transaction, $category);
            }
        }
    }

    /**
     * The closest transaction in time of another account of the user, that may be the other side of a transfer.
     *
     * @param list<Transaction> $candidates of the opposite amount, in the same currency
     * @param array<int, true>  $paired
     */
    private function otherSideOf(Transaction $transaction, array $candidates, array $paired): ?Transaction
    {
        $closest = null;
        $closestDays = self::MAX_DAYS + 1;
        foreach ($candidates as $candidate) {
            if ($candidate->getAccount() === $transaction->getAccount() || isset($paired[spl_object_id($candidate)]) || null !== $candidate->getCategory()) {
                continue;
            }

            $days = (int) $transaction->getDate()->diff($candidate->getDate())->days;
            // Same amounts by chance aside: one side at least must say it's a transfer
            if ($days < $closestDays && ($this->isWordedAsTransfer($transaction) || $this->isWordedAsTransfer($candidate))) {
                $closest = $candidate;
                $closestDays = $days;
            }
        }

        return $closest;
    }

    private function isWordedAsTransfer(Transaction $transaction): bool
    {
        $words = $this->normalizer->words($transaction->getLabel());

        return array_any(self::PATTERNS, static fn (string $pattern): bool => 1 === preg_match($pattern, $words));
    }

    /**
     * Unless its series files it elsewhere already: the rent paid by transfer stays in "Rent".
     */
    private static function file(Transaction $transaction, Category $category): void
    {
        if (null === $transaction->getCategory() && null === $transaction->getSeriesCategory()) {
            $transaction->categorize($category);
        }
    }
}
