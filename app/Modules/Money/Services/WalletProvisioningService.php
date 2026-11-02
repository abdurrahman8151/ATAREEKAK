<?php

namespace App\Modules\Money\Services;

use App\Models\User;
use App\Models\Wallet;

/**
 * Wallet provisioning: the account-password re-read and the wallet row creation plus its link on the
 * user.
 *
 * Moved out of WalletController (batch 227) so the controller holds no Eloquent reference. Both
 * creation sites (the OTP flow and create-direct) shared the same four writes; they now share this
 * one method. `useWritePdo()` and the `$user->save()` write are kept exactly as they were, so the
 * primary-read decision (un11) and the updated_at touch are unchanged.
 */
final class WalletProvisioningService
{
    /**
     * The account password hash, read fresh from the primary.
     *
     * The JWT-cached user deliberately omits the password hash (decision un11), so this cannot read
     * the value off the request user.
     */
    public function passwordHashFor(int $userId): ?string
    {
        return User::query()->useWritePdo()->whereKey($userId)->value('password');
    }

    /**
     * Create the wallet, link it to the user, and hand back the two fields the 201 response carries.
     *
     * @return array{wallet_number: string|null, phone_number: string}
     */
    public function createFor(User $user, string $phoneNumber): array
    {
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'phone_number' => $phoneNumber,
            'balance' => 0,
        ]);

        $user->wallet_id = $wallet->id;
        $user->save();

        return [
            'wallet_number' => $wallet->wallet_number,
            'phone_number' => $wallet->phone_number,
        ];
    }
}
