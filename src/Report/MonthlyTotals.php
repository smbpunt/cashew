<?php

namespace App\Report;

/**
 * What came in and went out of the accounts in one currency, over a month, in minor units (cents).
 */
final readonly class MonthlyTotals
{
    public function __construct(
        public string $currency,
        public int $income = 0,
        /**
         * Negative, like the debits it adds up.
         */
        public int $spending = 0,
    ) {
    }

    public function add(int $amount): self
    {
        return $amount > 0
            ? new self($this->currency, $this->income + $amount, $this->spending)
            : new self($this->currency, $this->income, $this->spending + $amount);
    }

    public function getBalance(): int
    {
        return $this->income + $this->spending;
    }
}
