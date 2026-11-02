<?php

namespace App\Services\Admin;

use App\Domain\ValueObjects\Money;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Modules\Money\Contracts\LedgerServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Admin wallet charge (credit to a user wallet, funded from External Capital), moved out verbatim
 * from AdminWalletService.
 *
 * AdminWalletService keeps chargeWallet with the same signature and return type and delegates here,
 * so every caller and the transaction boundary are unchanged. The body is the original one: the
 * positive-amount guard, the row lock order, the admin_credit and external_inbound ledger types,
 * the missing-External exception and the returned array are all kept exactly.
 */
class AdminWalletCharger
{
    public function __construct(
        private readonly LedgerServiceInterface $ledgerService,
    ) {}

    public function chargeWallet(string $phoneNumber, Money $amount, array $adminConfig): array
    {
        // Body moved verbatim from AdminWalletService::chargeWallet.
        // `R2 sec 116`: `Money` is signed, and the constructor guard that used to keep a negative out
        // of here is gone - so the rule is stated where it belongs. A "charge" is a CREDIT. A negative
        // one would post `type = admin_credit` for a debit, move the target wallet DOWN, and move the
        // External Capital account UP, while every label in the ledger said money was arriving.
        // The HTTP entry point validates `min:1` already (`AdminDashboardController:253`); this is the
        // service-level guarantee, because the service is reachable from more than that one route.
        $amount->assertPositive('Wallet charge amount');

        return DB::transaction(function () use ($phoneNumber, $amount, $adminConfig) {
            $wallet = Wallet::where('phone_number', $phoneNumber)
                ->lockForUpdate()
                ->firstOrFail();

            $previousBalance = (float) $wallet->balance;
            $newBalance = $previousBalance + $amount->amount();

            $wallet->balance = $newBalance;
            $wallet->save();

            [$transaction, $transactionId] = $this->createAdminCreditTransaction(
                $wallet,
                $amount,
                $adminConfig,
                $previousBalance,
                $newBalance,
            );

            $this->postExternalInboundLeg($wallet, $transaction, $amount, $adminConfig, $transactionId);

            Log::info('Wallet charged by admin', [
                'wallet_id' => $wallet->id,
                'amount' => $amount->amount(),
                'admin_type' => $adminConfig['type'],
            ]);

            return [
                'wallet' => $wallet,
                'transaction' => $transaction,
                'previous_balance' => Money::from($previousBalance),
                'new_balance' => Money::from($newBalance),
            ];
        });
    }

    /**
     * The admin_credit leg: the target wallet transaction. Returns the transaction plus the id
     * used for the matching external leg.
     *
     * @return array{0: WalletTransaction, 1: string}
     */
    private function createAdminCreditTransaction(
        Wallet $wallet,
        Money $amount,
        array $adminConfig,
        float $previousBalance,
        float $newBalance,
    ): array {
        $transactionId = strtoupper($adminConfig['type']).'_CHARGE_'.time().'_'.Str::random(8);

        $transaction = WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,   // null for system wallets
            'type' => 'admin_credit',
            'amount' => $amount->amount(),
            'previous_balance' => $previousBalance,
            'new_balance' => $newBalance,
            'description' => 'Admin wallet charge by '.$adminConfig['type'],
            'transaction_id' => $transactionId,
            'status' => 'completed',
            // NOTE: no 'metadata' column in wallet_transactions
        ]);

        return [$transaction, $transactionId];
    }

    /**
     * The external_inbound leg: money leaves the External Capital account and the transfer is
     * posted through the ledger. Throws when that account is missing, because the ledger cannot
     * close without it.
     */
    private function postExternalInboundLeg(
        Wallet $wallet,
        WalletTransaction $transaction,
        Money $amount,
        array $adminConfig,
        string $transactionId,
    ): void {
        // ── Decision un3 (owner choice (a)): this is an EXTERNAL flow ────────────
        // Money arriving in the platform from the outside world has no internal counterparty,
        // so it is recorded against the External Capital account. That closes the ledger:
        // without it, an admin top-up was a balance change with no ledger entry, and
        // reconciliation had a permanent hole exactly where money enters the system.
        //
        // The account is NOT Primary Escrow - that one is platform revenue.
        $external = Wallet::where('phone_number', config('admin.external.phone'))
            ->where('kind', 'system')
            ->whereNull('user_id')
            ->lockForUpdate()
            ->first();

        if ($external === null) {
            // The external account must exist for the ledger to close. Failing loudly beats
            // silently recording money that the ledger cannot explain.
            throw new RuntimeException(
                'External Capital wallet not found for phone: '.config('admin.external.phone')
                .'. Run: php artisan db:seed --class=SystemWalletSeeder'
            );
        }

        $externalPrev = (float) $external->balance;
        $external->balance -= $amount->amount();   // money leaves the outside account
        $external->save();

        WalletTransaction::create([
            'wallet_id' => $external->id,
            'user_id' => null,
            'type' => 'external_inbound',
            'amount' => -$amount->amount(),
            'previous_balance' => $externalPrev,
            'new_balance' => (float) $external->balance,
            'description' => 'Funds injected into wallet #'.$wallet->id.' by '.$adminConfig['type'],
            'transaction_id' => 'EXT_'.$transactionId,
            'status' => 'completed',
        ]);

        $this->ledgerService->postExternalTransfer(
            $external,
            $wallet,
            (float) $amount->amount(),
            inbound: true,
            transaction: $transaction,
            description: "admin credit to wallet #{$wallet->id}",
        );
    }
}
