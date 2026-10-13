<?php

namespace App\Entity;

/**
 * What the user names for transactions to go by: a counterparty, or a series of it.
 */
interface Nameable
{
    public function getName(): string;

    /**
     * A blank name takes it back.
     */
    public function rename(?string $name): void;
}
