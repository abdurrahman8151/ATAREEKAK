<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Contracts\PasswordVerifier;
use Illuminate\Support\Facades\Hash;

/**
 * Stage 3 A4: the account-password check, inside the module that owns the `users.password` column.
 *
 * One query on the WRITE PDO - the same read the retired `WalletProvisioningService::passwordHashFor()` performed
 * from Money - and the hash never crosses the module boundary: the caller receives a boolean.
 */
final class PasswordVerifierService implements PasswordVerifier
{
    public function verify(int $userId, string $plain): bool
    {
        $hash = User::query()->useWritePdo()->whereKey($userId)->value('password');

        if (! $hash) {
            return false;
        }

        return Hash::check($plain, (string) $hash);
    }
}
