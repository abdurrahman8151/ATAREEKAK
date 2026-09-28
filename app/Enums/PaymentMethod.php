<?php

namespace App\Enums;

/**
 * Payment Method Enum
 */
enum PaymentMethod: string
{
    case CASH = 'cash';
    case E_PAY = 'e-pay';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Cash',
            self::E_PAY => 'E-Payment (Wallet)',
        };
    }

    /**
     * Check if payment method requires wallet
     */
    public function requiresWallet(): bool
    {
        return $this === self::E_PAY;
    }

    /**
     * Check if payment is processed immediately
     */
    public function isImmediate(): bool
    {
        return $this === self::E_PAY;
    }

    /**
     * Get all available payment methods
     */
    public static function available(): array
    {
        return [
            self::CASH->value,
            self::E_PAY->value,
        ];
    }
}
