<?php

namespace App\Calendar;

use App\Entity\Category;
use App\Entity\RecurringSeries;
use App\Entity\Transaction;

/**
 * A debit on a day of the calendar: a transaction, or an occurrence a recurring series is expected to have.
 */
final readonly class CalendarEntry
{
    private function __construct(
        public \DateTimeImmutable $date,
        public string $name,
        /**
         * In minor units (cents), negative.
         */
        public int $amount,
        public string $currency,
        public ?Category $category,
        /**
         * Null for an occurrence expected.
         */
        public ?Transaction $transaction,
        /**
         * The confirmed series it is, or is expected, of.
         */
        public ?RecurringSeries $series,
    ) {
    }

    public static function of(Transaction $transaction): self
    {
        return new self(
            $transaction->getDate(),
            $transaction->getName(),
            $transaction->getAmount(),
            $transaction->getCurrency(),
            $transaction->getEffectiveCategory(),
            $transaction,
            $transaction->isRecurring() ? $transaction->getRecurringSeries() : null,
        );
    }

    /**
     * An occurrence of the series expected that day, at its typical amount.
     */
    public static function expectedOf(RecurringSeries $series, \DateTimeImmutable $date): self
    {
        return new self($date, $series->getName(), $series->getTypicalAmount(), $series->getCurrency(), $series->getCategory(), null, $series);
    }

    public function isExpected(): bool
    {
        return null === $this->transaction;
    }
}
