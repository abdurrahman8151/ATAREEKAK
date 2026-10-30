<?php

namespace App\Interfaces\Payment;

use App\Domain\Payment\Strategies\PaymentStrategy;
use App\Enums\PaymentMethod;

/**
 * Port over the payment-strategy selector (batch 233).
 *
 * RIDE decides which payment method a ride uses; MONEY owns the strategies that execute it.
 * Before this port `BookingService` and `BookingCompletionService` type-hinted
 * `App\Domain\Payment\Strategies\PaymentStrategyFactory` directly.
 *
 * `make()` deliberately declares NO return type here: the concrete return is
 * `App\Domain\Payment\Strategies\PaymentStrategy`, and naming that in the shared kernel would
 * make the kernel depend on the MONEY context. An interface method may omit the return type and
 * the implementation may narrow it (PHP return-type covariance), so this stays a one-way
 * dependency: RIDE -> App\Interfaces -> App\Services\Payment. Callers receive the identical
 * strategy object the factory returned before.
 */
interface PaymentStrategyFactoryInterface
{
    /**
     * Create the payment strategy for the given method.
     *
     * @return PaymentStrategy
     *
     * @throws \InvalidArgumentException if no strategy matches
     */
    public function make(string|PaymentMethod $paymentMethod);
}
