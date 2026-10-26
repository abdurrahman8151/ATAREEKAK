<?php

namespace App\Services\Payment;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Debt auto-clear: after an approved top-up, a wallet whose balance covers its cash_ride_debt has
 * that debt moved to the primary admin wallet as revenue.
 *
 * Split out of CashRideFeeService (behaviour unchanged). Bodies moved verbatim, so the ledger legs
 * and the posting keys are untouched.
 */
final class CashRideDebtClearanceService
{
    public function __construct(
        private readonly PrimaryAdminWallet $primaryAdminWallet,
        private readonly LedgerService $ledgerService,
    ) {}

    /**
     * If wallet.balance >= wallet.cash_ride_debt, deduct the debt automatically
     * and transfer it to the Primary Admin wallet as platform revenue.
     *
     * Fails silently when balance is still insufficient — no exception thrown
     * so the top-up approval response is never blocked.
     */
    public function autoClearDebt(Wallet $wallet, User $driver): void
    {
        $debt = (float) $wallet->cash_ride_debt;

        if ($debt <= 0) {
            return;
        }

        $lockedWallet = $this->lockPayableWallet($wallet);

        if (! $lockedWallet) {
            return;
        }

        $debt = (float) $lockedWallet->cash_ride_debt;
        $primaryWallet = $this->primaryAdminWallet->lock();
        $txId = 'DEBT_CLEAR_'.time().'_'.Str::random(6);
        $prevBalance = (float) $lockedWallet->balance;
        $prevDebt = $debt;
        $primaryPrev = (float) $primaryWallet->balance;

        $lockedWallet->balance -= $debt;
        $lockedWallet->cash_ride_debt = 0;
        $primaryWallet->balance += $debt;

        $lockedWallet->save();
        $primaryWallet->save();

        $this->recordDebtClearedTransactions($lockedWallet, $primaryWallet, $driver, $debt, $prevBalance, $prevDebt, $primaryPrev, $txId);

        $this->postDebtClearLegs($lockedWallet, $primaryWallet, $driver, $debt);

        Log::info('Cash ride debt auto-cleared after top-up', [
            'driver_id' => $driver->id,
            'wallet_id' => $lockedWallet->id,
            'debt_cleared' => $prevDebt,
            'new_balance' => $lockedWallet->balance,
        ]);
    }

    /**
     * Re-lock the wallet inside a transaction to prevent races, and return it only
     * when it still exists and the debt is covered by the locked balance.
     */
    private function lockPayableWallet(Wallet $wallet): ?Wallet
    {
        $lockedWallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

        if (! $lockedWallet) {
            return null;
        }

        $debt = (float) $lockedWallet->cash_ride_debt;

        if ($debt <= 0 || (float) $lockedWallet->balance < $debt) {
            // Balance still insufficient — leave debt in place, driver must top up more
            return null;
        }

        return $lockedWallet;
    }

    /** Both legs of the debt settlement: driver wallet debit and platform credit. */
    private function recordDebtClearedTransactions(Wallet $lockedWallet, Wallet $primaryWallet, User $driver, float $debt, float $prevBalance, float $prevDebt, float $primaryPrev, string $txId): void
    {
        WalletTransaction::create([
            'wallet_id' => $lockedWallet->id,
            'user_id' => $driver->id,
            'type' => 'cash_ride_debt_cleared',
            'amount' => -$debt,
            'previous_balance' => $prevBalance,
            'new_balance' => (float) $lockedWallet->balance,
            'description' => "Cash ride creation fee debt auto-cleared ({$debt} SYP) after wallet top-up.",
            'transaction_id' => $txId,
            'status' => 'completed',
            'reference' => "wallet:{$lockedWallet->id}",
        ]);

        WalletTransaction::create([
            'wallet_id' => $primaryWallet->id,
            'user_id' => null,
            'type' => 'cash_ride_debt_received',
            'amount' => $debt,
            'previous_balance' => $primaryPrev,
            'new_balance' => (float) $primaryWallet->balance,
            'description' => "Cash ride fee debt payment received from driver #{$driver->id} ({$debt} SYP).",
            'transaction_id' => 'PRIMARY_'.$txId,
            'status' => 'completed',
            'reference' => "wallet:{$lockedWallet->id}",
        ]);
    }

    private function postDebtClearLegs(Wallet $lockedWallet, Wallet $primaryWallet, User $driver, float $debt): void
    {
        // ── Decision un3: double-entry legs (driver → platform) ──────────────────
        // This is where the DEFERRED fee from `chargeCashRideCreationFee` finally becomes a real
        // movement: the driver topped up, so the debt is collected now. The deferred branch posted no
        // legs because nothing moved then; this is the leg pair for that obligation being settled.
        $this->ledgerService->postTransfer([
            ['wallet_id' => $lockedWallet->id, 'amount' => -$debt, 'description' => "driver #{$driver->id}: deferred fee debt cleared"],
            ['wallet_id' => $primaryWallet->id, 'amount' => $debt, 'description' => "driver #{$driver->id}: deferred fee received"],
        ]);
    }
}
