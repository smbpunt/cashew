<?php

namespace App\Enum;

enum Bank: string
{
    case CreditAgricole = 'credit_agricole';
    case Revolut = 'revolut';

    public function label(): string
    {
        return match ($this) {
            self::CreditAgricole => 'Crédit Agricole',
            self::Revolut => 'Revolut',
        };
    }
}
