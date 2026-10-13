<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How the dashboard lists the transactions of a month.
 */
enum TransactionListMode: string implements TranslatableInterface
{
    /**
     * The date, the label and the amount.
     */
    case Simple = 'simple';

    /**
     * Also the whole label, the account, the bank, the category and the kind of transaction.
     */
    case Detailed = 'detailed';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('dashboard.mode.'.$this->value, locale: $locale);
    }
}
