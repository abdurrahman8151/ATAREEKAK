<?php

namespace App\Services\Payment;

use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\PostingKey;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cash ride creation fee (deferred or immediate), moved out verbatim from CashRideFeeService.
 *
 * CashRideFeeService keeps chargeCashRideCreationFee with the same signature and return type and
 * delegates here, so every caller is unchanged. The body and the lockPrimaryWallet helper are the
 * original ones: the once-only posting keys, the deferred debt branch, the immediate driver-to-primary
 * transfer, the ledger type names and the missing-primary refusal are all kept exactly.
 */
class CashRideCreationFeeCharger
{
    public function chargeCashRideCreationFee(Ride $ride, User $driver): void
    {
        $feeAmount = (float) $ride->cash_creation_fee;
        $driverWallet = Wallet::where('user_id', $driver->id)
            ->lockForUpdate()
            ->firstOrFail();

        // RV-02 L2: a ride is created once, so its creation fee is a once-only posting. Keyed
        // per ride AND per branch, because a ride's fee is EITHER deferred or charged, never both.
        $deferredKey = PostingKey::build('ride:'.$ride->id, 'cash-fee', 'deferred');
        $chargeKey = PostingKey::build('ride:'.$ride->id, 'cash-fee', 'charge');

        if ($ride->cash_fee_deferred) {
            PostingKey::assertUnused($deferredKey);

            // ── Deferred: add to debt, no balance change ──────────────────────
            $prevDebt = (float) $driverWallet->cash_ride_debt;
            $driverWallet->cash_ride_debt = $prevDebt + $feeAmount;
            $driverWallet->save();

            WalletTransaction::create([
                'wallet_id' => $driverWallet->id,
                'user_id' => $driver->id,
                'type' => 'cash_ride_fee_deferred',
                'amount' => 0,
                'previous_balance' => (float) $driverWallet->balance,
                'new_balance' => (float) $driverWallet->balance,
                'description' => "Cash ride creation fee deferred ({$feeAmount} SYP) — ride #{$ride->id}. "
                    .'Balance unchanged; debt now '.$driverWallet->cash_ride_debt.' SYP.',
                'transaction_id' => 'CASH_DEFER_'.time().'_'.Str::random(6),
                'status' => 'completed',
                'reference' => "ride:{$ride->id}",
                'posting_key' => $deferredKey,
            ]);

            Log::info('Cash ride fee deferred', [
                'ride_id' => $ride->id,
                'driver_id' => $driver->id,
                'fee' => $feeAmount,
                'new_debt' => $driverWallet->cash_ride_debt,
            ]);

            return;
        }

        // ── Immediate: Driver wallet → Primary Admin wallet ──────────────────
        PostingKey::assertUnused($chargeKey);

        $primaryWallet = $this->lockPrimaryWallet();
        $txId = 'CASH_FEE_'.time().'_'.Str::random(6);
        $driverPrev = (float) $driverWallet->balance;
        $primaryPrev = (float) $primaryWallet->balance;

        $driverWallet->balance -= $feeAmount;
        $primaryWallet->balance += $feeAmount;

        $driverWallet->save();
        $primaryWallet->save();

        WalletTransaction::create([
            'wallet_id' => $driverWallet->id,
            'user_id' => $driver->id,
            'type' => 'cash_ride_creation_fee',
            'amount' => -$feeAmount,
            'previous_balance' => $driverPrev,
            'new_balance' => (float) $driverWallet->balance,
            'description' => "Cash ride creation fee (5%) — ride #{$ride->id}: "
                ."{$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id' => $txId,
            'status' => 'completed',
            'reference' => "ride:{$ride->id}",
            'posting_key' => $chargeKey,
        ]);

        WalletTransaction::create([
            'wallet_id' => $primaryWallet->id,
            'user_id' => null,
            'type' => 'cash_ride_creation_fee_received',
            'amount' => $feeAmount,
            'previous_balance' => $primaryPrev,
            'new_balance' => (float) $primaryWallet->balance,
            'description' => "Cash ride creation fee received from driver #{$driver->id} — ride #{$ride->id}",
            'transaction_id' => 'PRIMARY_'.$txId,
            'status' => 'completed',
            'reference' => "ride:{$ride->id}",
        ]);

        // ── Decision un3: double-entry legs (driver → platform) ──────────────────
        // NOTE the DEFERRED branch above posts NO legs, deliberately: it moves no money (it raises
        // `cash_ride_debt`, and the balance row above records `amount = 0`). A 0.00 transfer is not a
        // transfer; posting legs for it would pad the ledger with entries that say nothing moved.
        // Debt is an obligation, and it becomes a real movement when it is later charged or cleared -
        // which those methods record.
        app(LedgerService::class)->postTransfer([
            ['wallet_id' => $driverWallet->id, 'amount' => -$feeAmount, 'description' => "ride #{$ride->id}: cash creation fee"],
            ['wallet_id' => $primaryWallet->id, 'amount' => $feeAmount, 'description' => "ride #{$ride->id}: fee received by platform"],
        ]);

        Log::info('Cash ride fee charged immediately', [
            'ride_id' => $ride->id,
            'driver_id' => $driver->id,
            'fee' => $feeAmount,
        ]);
    }

    private function lockPrimaryWallet(): Wallet
    {
        $wallet = Wallet::where('phone_number', config('admin.system_admin.phone'))
            ->lockForUpdate()
            ->first();

        if (! $wallet) {
            throw new \RuntimeException(
                'Primary Admin wallet not found. Run: php artisan db:seed --class=SystemWalletSeeder'
            );
        }

        return $wallet;
    }
}
