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
}
