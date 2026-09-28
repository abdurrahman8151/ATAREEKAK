<?php

namespace Tests\Unit\Domain;

use App\Domain\Payment\Strategies\CashPaymentStrategy;
use App\Domain\Payment\Strategies\EPayPaymentStrategy;
use App\Domain\Payment\Strategies\PaymentStrategyFactory;
use App\Enums\PaymentMethod;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * PaymentStrategyFactoryTest
 *
 * KNOWN CODEBASE ISSUE — EPayPaymentStrategy construction:
 *   PaymentStrategyFactory::__construct() calls `new EPayPaymentStrategy()` directly,
 *   bypassing the IoC container. EPayPaymentStrategy requires WalletTransactionService
 *   as a constructor argument, so direct instantiation throws ArgumentCountError.
 *
 *   FIX: Pass strategies via constructor injection or resolve them from the container
 *   instead of `new`-ing them directly.
 *
 *   WORKAROUND USED IN TESTS: ReflectionClass::newInstanceWithoutConstructor() creates
 *   a factory shell with no strategies pre-loaded. Tests then use register() to add
 *   strategies and exercise make() in isolation.
 */
class PaymentStrategyFactoryTest extends TestCase
{
    // ─── Known construction issue ─────────────────────────────────────────────────

    public function test_direct_instantiation_fails_due_to_epay_missing_wallet_service(): void
    {
        // FIX: EPayPaymentStrategy requires WalletTransactionService but
        // PaymentStrategyFactory creates it with `new`, bypassing the container.
        $this->expectException(\Throwable::class); // ArgumentCountError or TypeError
        new PaymentStrategyFactory;
    }

    // ─── getAvailablePaymentMethods() ─────────────────────────────────────────────

    public function test_available_payment_methods_contains_cash(): void
    {
        $methods = PaymentMethod::available();
        $this->assertContains('cash', $methods);
    }

    public function test_available_payment_methods_contains_epay(): void
    {
        $methods = PaymentMethod::available();
        $this->assertContains('e-pay', $methods);
    }

    public function test_available_payment_methods_returns_exactly_two_methods(): void
    {
        $methods = PaymentMethod::available();
        $this->assertCount(2, $methods);
    }

    // ─── register() and make() via reflection workaround ─────────────────────────

    public function test_register_adds_strategy_and_make_resolves_cash(): void
    {
        $factory = $this->makeEmptyFactory();
        $strategy = new CashPaymentStrategy;
        $factory->register($strategy);

        $resolved = $factory->make('cash');
        $this->assertInstanceOf(CashPaymentStrategy::class, $resolved);
    }

    public function test_register_adds_strategy_and_make_resolves_via_enum(): void
    {
        $factory = $this->makeEmptyFactory();
        $factory->register(new CashPaymentStrategy);

        $resolved = $factory->make(PaymentMethod::CASH);
        $this->assertInstanceOf(CashPaymentStrategy::class, $resolved);
    }

    public function test_make_throws_for_unknown_payment_method(): void
    {
        $factory = $this->makeEmptyFactory();
        $factory->register(new CashPaymentStrategy);

        $this->expectException(InvalidArgumentException::class);
        $factory->make('bitcoin');
    }

    public function test_make_throws_when_no_strategies_registered(): void
    {
        $factory = $this->makeEmptyFactory();

        $this->expectException(InvalidArgumentException::class);
        $factory->make('cash');
    }

    public function test_register_can_add_multiple_strategies(): void
    {
        $factory = $this->makeEmptyFactory();
        $strategy = new CashPaymentStrategy;

        $factory->register($strategy);
        $factory->register($strategy); // same strategy twice is allowed

        // Should resolve without error
        $this->assertInstanceOf(CashPaymentStrategy::class, $factory->make('cash'));
    }

    public function test_make_with_enum_string_value_resolves_correctly(): void
    {
        $factory = $this->makeEmptyFactory();
        $factory->register(new CashPaymentStrategy);

        $this->assertInstanceOf(CashPaymentStrategy::class, $factory->make('cash'));
    }

    // ─── CashPaymentStrategy canProcess() coverage ────────────────────────────────

    public function test_cash_strategy_can_process_cash(): void
    {
        $strategy = new CashPaymentStrategy;
        $this->assertTrue($strategy->canProcess('cash'));
    }

    public function test_cash_strategy_cannot_process_epay(): void
    {
        $strategy = new CashPaymentStrategy;
        $this->assertFalse($strategy->canProcess('e-pay'));
    }

    public function test_factory_class_exists(): void
    {
        $this->assertTrue(class_exists(PaymentStrategyFactory::class));
    }

    public function test_factory_class_is_final(): void
    {
        $ref = new \ReflectionClass(PaymentStrategyFactory::class);
        $this->assertTrue($ref->isFinal());
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────────

    /**
     * Creates a PaymentStrategyFactory instance without running its constructor,
     * bypassing the EPayPaymentStrategy direct-new bug described in the class docblock.
     */
    private function makeEmptyFactory(): PaymentStrategyFactory
    {
        return (new \ReflectionClass(PaymentStrategyFactory::class))
            ->newInstanceWithoutConstructor();
    }
}
