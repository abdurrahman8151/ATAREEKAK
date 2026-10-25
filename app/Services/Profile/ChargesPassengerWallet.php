<?php

namespace App\Services\Profile;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Payment\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The wallet-charging mechanics behind POST /api/admin/passengers/{userId}/charge-wallet:
 * the "no wallet yet" reply, crediting the balance, the external-capital ledger row, the cache
 * bust and the success envelope.
 *
 * Split out of PassengerProfileController (behaviour unchanged). The two helpers that read the
 * authenticated admin from the request (recordAdminChargeTransaction, notifyWalletCharged) stay
 * in the controller, which is the HTTP boundary. Bodies moved verbatim.
 */
trait ChargesPassengerWallet
{
    /** The 422 for a passenger who has no wallet row yet. */
    private function noWalletResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'This user does not have a wallet yet.',
        ], 422);
    }

    /**
     * Lock the passenger wallet and add the amount.
     *
     * @return array{wallet: Wallet, previous: float, current: float}
     */
    private function creditWalletBalance(User $user, float $amount): array
    {
        $wallet = Wallet::lockForUpdate()->findOrFail($user->wallet->id);

        $previousBalance = (float) $wallet->balance;
        $newBalance = $previousBalance + $amount;
        $wallet->balance = $newBalance;
        $wallet->save();

        return ['wallet' => $wallet, 'previous' => $previousBalance, 'current' => $newBalance];
    }

    /**
     * Record the inbound leg against the External Capital system wallet and post the
     * matching ledger transfer.
     */
    private function injectExternalCapital($wallet, User $user, float $amount): void
    {
        $external = Wallet::where('phone_number', config('admin.external.phone'))
            ->where('kind', 'system')
            ->whereNull('user_id')
            ->lockForUpdate()
            ->first();

        if ($external === null) {
            throw new \RuntimeException(
                'External Capital wallet not found for phone: '.config('admin.external.phone')
                .'. Run: php artisan db:seed --class=SystemWalletSeeder'
            );
        }

        $externalPrev = (float) $external->balance;
        $external->balance -= $amount;
        $external->save();

        WalletTransaction::create([
            'wallet_id' => $external->id,
            'user_id' => null,
            'type' => 'external_inbound',
            'amount' => -$amount,
            'previous_balance' => $externalPrev,
            'new_balance' => (float) $external->balance,
            'description' => 'Injected into wallet #'.$wallet->id.' by admin charge',
            'transaction_id' => 'EXT-ADM-'.$user->id.'-'.(string) Str::uuid(),
            'status' => 'completed',
            'reference' => 'admin_charge:'.$user->id,
        ]);

        app(LedgerService::class)->postExternalTransfer(
            $external,
            $wallet,
            (float) $amount,
            inbound: true,
            description: "admin charge to user #{$user->id}",
        );
    }

    /** Bust every cached admin view that embeds the wallet balance or its charges. */
    private function bustWalletChargeCaches(int $userId): void
    {
        Cache::forget("admin.passenger.full-profile.{$userId}");
        Cache::forget("admin.passenger.stats.{$userId}");
        Cache::forget('admin.dashboard.data');
        Cache::forget('admin.dashboard.stats');
    }

    /** The POST charge-wallet success envelope, read from the refreshed wallet row. */
    private function chargeSuccessResponse($wallet): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => "Wallet charged successfully. New balance: {$wallet->balance} SYP.",
            'new_balance' => (float) $wallet->balance,
        ]);
    }
}
