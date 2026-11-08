<?php

namespace App\Modules\Money\Providers;

use App\Modules\Money\Contracts\BookingChargeInterface;
use App\Modules\Money\Contracts\CancellationRefundInterface;
use App\Modules\Money\Contracts\CashRideFeeServiceInterface;
use App\Modules\Money\Contracts\DriverEarningsReader;
use App\Modules\Money\Contracts\LedgerAggregates;
use App\Modules\Money\Contracts\LedgerReconciliation;
use App\Modules\Money\Contracts\LedgerServiceInterface;
use App\Modules\Money\Contracts\NoShowSettlementInterface;
use App\Modules\Money\Contracts\PassengerWalletChargerInterface;
use App\Modules\Money\Contracts\PaymentStrategyFactoryInterface;
use App\Modules\Money\Contracts\RidePayoutInterface;
use App\Modules\Money\Contracts\WalletChargeHistory;
use App\Modules\Money\Contracts\WalletDirectory;
use App\Modules\Money\Contracts\WalletTransactionPager;
use App\Modules\Money\Contracts\WalletTransactionRecorder;
use App\Modules\Money\Domain\Strategies\CashPaymentStrategy;
use App\Modules\Money\Domain\Strategies\EPayPaymentStrategy;
use App\Modules\Money\Domain\Strategies\PaymentStrategyFactory;
use App\Modules\Money\Services\AdminWalletTransactionPager;
use App\Modules\Money\Services\BookingCharge;
use App\Modules\Money\Services\CancellationRefund;
use App\Modules\Money\Services\CashRideFeeService;
use App\Modules\Money\Services\DriverEarningsReaderService;
use App\Modules\Money\Services\LedgerAggregateReader;
use App\Modules\Money\Services\LedgerReconciliationReader;
use App\Modules\Money\Services\LedgerService;
use App\Modules\Money\Services\NoShowSettlement;
use App\Modules\Money\Services\PassengerWalletCharger;
use App\Modules\Money\Services\RidePayout;
use App\Modules\Money\Services\WalletAccess;
use App\Modules\Money\Services\WalletChargeHistoryReader;
use App\Modules\Money\Services\WalletDirectoryReader;
use App\Modules\Money\Services\WalletRequestService;
use App\Modules\Money\Services\WalletTransactionRecorderService;
use Illuminate\Support\ServiceProvider;

/**
 * Stage 1, phase 1(a): the Money module's container bindings, moved verbatim out of
 * `App\Support\ContainerBindings`. Lifetimes are unchanged (`singleton` stays `singleton`, `bind`
 * stays `bind`), and each port still resolves to the same singleton as before.
 *
 * The payment STRATEGIES are registered here even though they live in `App\Modules\Money\Domain\Strategies`: the
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
        // Stage 2 C2: the admin driver dashboard's earnings sum, owned by the module with the table.
        $this->app->bind(DriverEarningsReader::class, DriverEarningsReaderService::class);
        // Stage 2 C2: the admin wallet-transaction page, owned by the module with the table.
        $this->app->bind(WalletTransactionPager::class, AdminWalletTransactionPager::class);
        // Stage 2 C2: the admin report's four ledger totals, with the type spellings Money owns.
        $this->app->bind(LedgerAggregates::class, LedgerAggregateReader::class);
        // Stage 2 C2: `ledger:reconcile` asks Money whether the ledger explains the balances.
        $this->app->bind(LedgerReconciliation::class, LedgerReconciliationReader::class);

        $this->app->singleton(WalletAccess::class);
        // Stage 2 C1: the read door other modules use instead of `$user->wallet`.
        $this->app->bind(WalletDirectory::class, WalletDirectoryReader::class);
        $this->app->bind(WalletChargeHistory::class, WalletChargeHistoryReader::class);
        $this->app->bind(WalletTransactionRecorder::class, WalletTransactionRecorderService::class);
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
