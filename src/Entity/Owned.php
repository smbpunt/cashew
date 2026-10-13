<?php

namespace App\Entity;

/**
 * What belongs to a user, and only they may see or change.
 */
interface Owned
{
    public function getOwner(): User;
}
