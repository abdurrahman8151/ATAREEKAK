<?php

namespace App\Domain\Payment\Strategies;

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
    ) {}

    public static function success(
        string $message = 'Payment processed successfully',
        ?array $transactionIds = null
    ): self {
        return new self(true, $message, $transactionIds);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}
