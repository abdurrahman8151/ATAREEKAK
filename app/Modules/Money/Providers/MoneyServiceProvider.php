<?php

namespace App\Modules\Money\Providers;

use App\Domain\Payment\Strategies\CashPaymentStrategy;
use App\Domain\Payment\Strategies\EPayPaymentStrategy;
use App\Domain\Payment\Strategies\PaymentStrategyFactory;
use App\Modules\Money\Contracts\BookingChargeInterface;
use App\Modules\Money\Contracts\CancellationRefundInterface;
use App\Modules\Money\Contracts\CashRideFeeServiceInterface;
use App\Modules\Money\Contracts\LedgerServiceInterface;
use App\Modules\Money\Contracts\NoShowSettlementInterface;
use App\Modules\Money\Contracts\PassengerWalletChargerInterface;
use App\Modules\Money\Contracts\PaymentStrategyFactoryInterface;
use App\Modules\Money\Contracts\RidePayoutInterface;
use App\Services\Payment\BookingCharge;
use App\Services\Payment\CancellationRefund;
use App\Services\Payment\CashRideFeeService;
use App\Services\Payment\LedgerService;
use App\Services\Payment\NoShowSettlement;
use App\Services\Payment\PassengerWalletCharger;
use App\Services\Payment\RidePayout;
use App\Services\Payment\WalletAccess;
use App\Services\Wallet\WalletRequestService;
use Illuminate\Support\ServiceProvider;

/**
 * Stage 1, phase 1(a): the Money module's container bindings, moved verbatim out of
 * `App\Support\ContainerBindings`. Lifetimes are unchanged (`singleton` stays `singleton`, `bind`
 * stays `bind`), and each port still resolves to the same singleton as before.
 *
 * The payment STRATEGIES are registered here even though they live in `App\Domain\Payment`: the
 * strategies are the money context's own domain code (batch 233 put the money domain inside the
 * money context), and the factory that picks between them is bound to its port immediately after.
 */
final class MoneyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CashRideFeeService::class);
        $this->app->singleton(BookingCharge::class);
        $this->app->singleton(RidePayout::class);
        $this->app->singleton(CancellationRefund::class);
        $this->app->singleton(NoShowSettlement::class);
        $this->app->singleton(WalletAccess::class);
        $this->app->singleton(EPayPaymentStrategy::class);
        $this->app->singleton(CashPaymentStrategy::class);
        $this->app->singleton(PaymentStrategyFactory::class);
        $this->app->singleton(WalletRequestService::class);

        // Ports (batch 227/233): the ride and identity contexts reach money code only through these.
        $this->app->bind(BookingChargeInterface::class, BookingCharge::class);
        $this->app->bind(RidePayoutInterface::class, RidePayout::class);
        $this->app->bind(CancellationRefundInterface::class, CancellationRefund::class);
        $this->app->bind(NoShowSettlementInterface::class, NoShowSettlement::class);
        $this->app->bind(CashRideFeeServiceInterface::class, CashRideFeeService::class);
        $this->app->bind(PaymentStrategyFactoryInterface::class, PaymentStrategyFactory::class);
        $this->app->bind(LedgerServiceInterface::class, LedgerService::class);
        $this->app->bind(PassengerWalletChargerInterface::class, PassengerWalletCharger::class);
    }
}
