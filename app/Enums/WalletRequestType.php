<?php

namespace App\Enums;

enum WalletRequestType: string
{
    case TOP_UP = 'charge';   // matches WalletRequest::create(['type' => 'charge', ...])
    case WITHDRAWAL = 'withdraw'; // matches WalletRequest::create(['type' => 'withdraw', ...])

    public function label(): string
    {
        return match ($this) {
            self::TOP_UP => 'Wallet Top-Up',
            self::WITHDRAWAL => 'Wallet Withdrawal',
        };
    }
}
