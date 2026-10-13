<?php

namespace App\Dashboard;

use App\Enum\TransactionListMode;
use App\Enum\TransactionSort;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Remembers how the user lists the transactions for the next visits: the mode and the sort, but not which ones are
 * shown, for a month of unknown transactions to stay an errand.
 */
final readonly class TransactionListPreferences
{
    private const string SESSION_KEY = 'dashboard_transaction_list';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function resolve(TransactionListQuery $query): TransactionListView
    {
        $session = $this->requestStack->getSession();
        $remembered = $session->get(self::SESSION_KEY);
        $remembered = \is_array($remembered) ? $remembered : [];

        $default = new TransactionListView();
        $view = new TransactionListView(
            $query->mode ?? TransactionListMode::tryFrom((string) ($remembered['mode'] ?? '')) ?? $default->mode,
            $query->sort ?? TransactionSort::tryFrom((string) ($remembered['sort'] ?? '')) ?? $default->sort,
            $query->show ?? $default->show,
        );
        $session->set(self::SESSION_KEY, ['mode' => $view->mode->value, 'sort' => $view->sort->value]);

        return $view;
    }
}
