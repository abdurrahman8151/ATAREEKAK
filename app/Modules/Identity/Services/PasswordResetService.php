<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * The OTP-driven password reset: find the account, replace the hash, consume the reset token and
 * revoke every live JWT.
 *
 * Moved out of ResetPasswordController (batch 227) so the controller holds no Eloquent reference.
 * The controller keeps the token cache read and every response branch; this service owns the
 * account lookup and the write, and takes the caller's ip as a plain string so it never sees a
 * Request. JwtService is injected instead of resolved with app(), which is the same object the
 * container would have handed back. The log line and the order of the steps are unchanged.
 */
final class PasswordResetService
{
    public function __construct(private readonly JwtService $jwtService) {}

    /**
     * Apply the reset for the account behind this address.
     *
     * @return bool false when no account exists for the address (the caller answers the uniform 400)
     */
    public function resetForEmail(string $email, string $newPassword, string $resetCacheKey, ?string $ip): bool
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            return false;
        }

        // Update password
        $user->password = Hash::make($newPassword);
        $user->save();

        // Consume the token — it must not be reusable
        Cache::forget($resetCacheKey);

        // Revoke all JWT tokens (increments token_version + deletes refresh tokens)
        $this->jwtService->revokeAllTokens($user->id);

        Log::info('Password reset via OTP flow', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $ip,
        ]);

        return true;
    }
}
