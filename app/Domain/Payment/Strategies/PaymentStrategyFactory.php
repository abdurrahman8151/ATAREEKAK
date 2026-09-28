<?php

namespace App\Domain\Payment\Strategies;

use App\Enums\PaymentMethod;
use App\Services\Payment\WalletTransactionService;
use InvalidArgumentException;

/**
 * Payment Strategy Factory
 *
 * FIXED: Injects WalletTransactionService via constructor so
 *        EPayPaymentStrategy receives its required dependency
 *        instead of being constructed with zero arguments
 *        (which threw ArgumentCountError → silent 500).
 */
final class PaymentStrategyFactory
{
    private array $strategies = [];

    public function __construct(
        private readonly WalletTransactionService $walletService,
    ) {
        $this->strategies = [
            new CashPaymentStrategy,
            new EPayPaymentStrategy($this->walletService),
        ];
    }

    /**
     * Create payment strategy for given payment method
     *
     * @throws InvalidArgumentException if no strategy found
     */
    public function make(string|PaymentMethod $paymentMethod): PaymentStrategy
    {
        $methodValue = $paymentMethod instanceof PaymentMethod
            ? $paymentMethod->value
            : $paymentMethod;

        foreach ($this->strategies as $strategy) {
            if ($strategy->canProcess($methodValue)) {
                return $strategy;
            }
        }

        throw new InvalidArgumentException(
            "No payment strategy found for payment method: {$methodValue}"
        );
    }

    /**
     * Get all available payment methods
     */
    public function getAvailablePaymentMethods(): array
    {
        return PaymentMethod::available();
    }

    /**
     * Register a new payment strategy
     */
    public function register(PaymentStrategy $strategy): void
    {
        $this->strategies[] = $strategy;
    }
}
