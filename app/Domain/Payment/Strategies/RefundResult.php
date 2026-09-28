<?php

namespace App\Domain\Payment\Strategies;

/**
 * Refund Result
 *
 * Returned from refund operations.
 * MOVED to its own file so PSR-4 autoloader can find it.
 */
final class RefundResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?array $transactionIds = null,
    ) {}

    public static function success(
        string $message = 'Refund processed successfully',
        ?array $transactionIds = null
    ): self {
        return new self(true, $message, $transactionIds);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}
