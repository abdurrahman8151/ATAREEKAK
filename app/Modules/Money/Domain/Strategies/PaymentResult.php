<?php

namespace App\Modules\Money\Domain\Strategies;

use App\DTOs\Money\BookingMoneySnapshot;

/**
 * Payment Result
 *
 * Returned from payment operations.
 * MOVED to its own file so PSR-4 autoloader can find it.
 */
final class PaymentResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?array $transactionIds = null,
        public readonly ?array $metadata = null,
        /**
         * Stage 4 A2: the money snapshot a charge computed, so the booking's OWNER persists it. Null for a
         * strategy that writes no snapshot (cash), which is the same "nothing written" the old column default gave.
         */
        public readonly ?BookingMoneySnapshot $snapshot = null,
    ) {}

    public static function success(
        string $message = 'Payment processed successfully',
        ?array $transactionIds = null,
        ?BookingMoneySnapshot $snapshot = null,
    ): self {
        return new self(true, $message, $transactionIds, null, $snapshot);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}
