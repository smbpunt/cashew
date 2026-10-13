<?php

namespace App\Enum;

enum TransactionKind: string
{
    case Regular = 'regular';

    /**
     * A purchase made with a deferred debit card: the account is debited later, in one go, by a settlement.
     */
    case DeferredCardPurchase = 'deferred_card_purchase';

    /**
     * The monthly debit of a deferred debit card whose purchases are imported one by one:
     * leave it out of spending totals, or the purchases count twice.
     */
    case DeferredCardSettlement = 'deferred_card_settlement';

    /**
     * The monthly debit of a deferred debit card whose purchases weren't imported (the export holds no card statement
     * that old): spent all the same, but it only adds up purchases, no recurring expense of its own.
     */
    case CardSettlement = 'card_settlement';

    /**
     * Money moved from one of the user's accounts to another, a pocket included: neither spent nor earned, it stays
     * out of the totals, on both sides.
     */
    case InternalTransfer = 'internal_transfer';

    /**
     * Whether transactions of this kind are listed with the others: a deferred card settlement only repeats the
     * purchases imported one by one.
     */
    public function isListed(): bool
    {
        return self::DeferredCardSettlement !== $this;
    }

    /**
     * Whether transactions of this kind count in what the user spent and earned.
     */
    public function countsInTotals(): bool
    {
        return $this->isListed() && self::InternalTransfer !== $this;
    }

    /**
     * Whether transactions of this kind may come back as a recurring series: a card purchase may be a subscription,
     * money put aside every month is, but what a card settlement adds up changes every month.
     */
    public function mayRecur(): bool
    {
        return $this->isListed() && self::CardSettlement !== $this;
    }

    /**
     * @return list<self>
     */
    public static function unlisted(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $kind): bool => !$kind->isListed()));
    }

    /**
     * @return list<self>
     */
    public static function nonRecurring(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $kind): bool => !$kind->mayRecur()));
    }
}
