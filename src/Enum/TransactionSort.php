<?php

namespace App\Enum;

use App\Entity\Transaction;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How the dashboard sorts the transactions of a month; those that tie, the most recent first.
 */
enum TransactionSort: string implements TranslatableInterface
{
    /**
     * The most recent first.
     */
    case Date = 'date';

    /**
     * The largest first, debits and credits alike.
     */
    case Amount = 'amount';

    /**
     * By the name the user knows them by, accents aside.
     */
    case Name = 'name';

    /**
     * @param list<Transaction> $transactions most recent first
     *
     * @return list<Transaction>
     */
    public function sort(array $transactions): array
    {
        $compare = match ($this) {
            self::Date => null,
            self::Amount => static fn (Transaction $a, Transaction $b): int => abs($b->getAmount()) <=> abs($a->getAmount()),
            self::Name => self::byName(new \Collator(\Locale::getDefault())),
        };

        // The sort is stable: those that tie stay the most recent first
        if (null !== $compare) {
            usort($transactions, $compare);
        }

        return $transactions;
    }

    /**
     * @return \Closure(Transaction, Transaction): int
     */
    private static function byName(\Collator $collator): \Closure
    {
        return static fn (Transaction $a, Transaction $b): int => (int) $collator->compare($a->getName(), $b->getName());
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('dashboard.sort.'.$this->value, locale: $locale);
    }
}
