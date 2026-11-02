<?php

namespace App\Modules\Money\Services;

use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cash ride creation fee (deferred or immediate), moved out verbatim from CashRideFeeService.
 *
 * CashRideFeeService keeps chargeCashRideCreationFee with the same signature and return type and
 * delegates here, so every caller is unchanged. The body is the original one: the once-only posting
 * keys, the deferred debt branch, the immediate driver-to-primary transfer, the ledger type names
 * and the missing-primary refusal are all kept exactly, and the platform-wallet lock is shared
 * through PrimaryAdminWallet.
 */
class CashRideCreationFeeCharger
{
    public function __construct(private readonly PrimaryAdminWallet $primaryAdminWallet, private readonly LedgerService $ledgerService) {}

    public function chargeCashRideCreationFee(Ride $ride, User $driver): void
    {
        $feeAmount = (float) $ride->cash_creation_fee;
        $driverWallet = Wallet::where('user_id', $driver->id)
            ->lockForUpdate()
            ->firstOrFail();

        // RV-02 L2: a ride is created once, so its creation fee is a once-only posting. Keyed
        // per ride AND per branch, because a ride's fee is EITHER deferred or charged, never both.
        ['deferred' => $deferredKey, 'charge' => $chargeKey] = $this->feePostingKeys($ride);

        if ($ride->cash_fee_deferred) {
            $this->chargeDeferredFee($ride, $driver, $driverWallet, $feeAmount, $deferredKey);

            return;
        }

        $this->chargeImmediateFee($ride, $driver, $driverWallet, $feeAmount, $chargeKey);
    }

    /**
     * The two once-only posting keys for a ride's creation fee, built in the original order.
     *
     * @return array{deferred: string, charge: string}
     */
    private function feePostingKeys(Ride $ride): array
    {
        return [
            'deferred' => PostingKey::build('ride:'.$ride->id, 'cash-fee', 'deferred'),
            'charge' => PostingKey::build('ride:'.$ride->id, 'cash-fee', 'charge'),
        ];
    }

    /** Deferred branch: raise the driver's cash-ride debt without moving any balance. */
    private function chargeDeferredFee(Ride $ride, User $driver, Wallet $driverWallet, float $feeAmount, string $deferredKey): void
    {
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
    }

    /** Immediate branch: Driver wallet → Primary Admin wallet, with the ledger legs after the rows. */
    private function chargeImmediateFee(Ride $ride, User $driver, Wallet $driverWallet, float $feeAmount, string $chargeKey): void
    {
        // ── Immediate: Driver wallet → Primary Admin wallet ──────────────────
        PostingKey::assertUnused($chargeKey);

        $primaryWallet = $this->primaryAdminWallet->lock();
        $txId = 'CASH_FEE_'.time().'_'.Str::random(6);

        $prev = $this->applyImmediateFeeCharge($driverWallet, $primaryWallet, $feeAmount);

        $this->recordFeeChargedRow($ride, $driver, $driverWallet, $prev['driver'], $feeAmount, $txId, $chargeKey);

        $this->recordFeeReceivedRow($ride, $driver, $primaryWallet, $prev['primary'], $feeAmount, $txId);

        $this->postFeeTransfer($ride, $driverWallet, $primaryWallet, $feeAmount);

        Log::info('Cash ride fee charged immediately', [
            'ride_id' => $ride->id,
            'driver_id' => $driver->id,
            'fee' => $feeAmount,
        ]);
    }

    /**
     * Snapshot both previous balances, move the fee from driver to primary and persist.
     *
     * @return array{driver: float, primary: float}
     */
    private function applyImmediateFeeCharge(Wallet $driverWallet, Wallet $primaryWallet, float $feeAmount): array
    {
        $prev = [
            'driver' => (float) $driverWallet->balance,
            'primary' => (float) $primaryWallet->balance,
        ];

        $driverWallet->balance -= $feeAmount;
        $primaryWallet->balance += $feeAmount;

        $driverWallet->save();
        $primaryWallet->save();

        return $prev;
    }

    /** The driver's debit row for the immediately charged creation fee. */
    private function recordFeeChargedRow(Ride $ride, User $driver, Wallet $driverWallet, float $driverPrev, float $feeAmount, string $txId, string $chargeKey): void
    {
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
    }

    /** The primary admin's credit row for the immediately charged creation fee. */
    private function recordFeeReceivedRow(Ride $ride, User $driver, Wallet $primaryWallet, float $primaryPrev, float $feeAmount, string $txId): void
    {
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
    }

    /**
     * Decision un3: double-entry legs (driver → platform).
     *
     * NOTE the DEFERRED branch posts NO legs, deliberately: it moves no money (it raises
     * `cash_ride_debt`, and the balance row records `amount = 0`). A 0.00 transfer is not a
     * transfer; posting legs for it would pad the ledger with entries that say nothing moved.
     * Debt is an obligation, and it becomes a real movement when it is later charged or cleared -
     * which those methods record.
     */
    private function postFeeTransfer(Ride $ride, Wallet $driverWallet, Wallet $primaryWallet, float $feeAmount): void
    {
        $this->ledgerService->postTransfer([
            ['wallet_id' => $driverWallet->id, 'amount' => -$feeAmount, 'description' => "ride #{$ride->id}: cash creation fee"],
            ['wallet_id' => $primaryWallet->id, 'amount' => $feeAmount, 'description' => "ride #{$ride->id}: fee received by platform"],
        ]);
    }
}
