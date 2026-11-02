<?php

namespace App\Modules\Identity\Services;

use App\Models\User;

/**
 * The last step of the e-mail verification flow: mark the address verified and hand back the
 * login payload.
 *
 * Moved out of EmailVerificationController (batch 227) so the controller holds no Eloquent
 * reference. The controller still runs `verifyOtp` first and keeps the 400 branch; this service
 * owns the `firstOrFail()` (so a missing row still surfaces as it did) and the response fields,
 * which are byte-for-byte the ones the controller built before.
 */
final class EmailVerificationService
{
    public function __construct(private readonly JwtService $jwtService) {}

    /**
     * @return array{user: array<string, mixed>, tokens: array<string, mixed>}
     */
    public function completeVerification(string $email): array
    {
        $user = User::where('email', $email)->firstOrFail();

        $user->update(['email_verified_at' => now()]);
        $user->refresh();

        return [
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
                'is_verified_passenger' => $user->is_verified_passenger,
                'is_verified_driver' => $user->is_verified_driver,
            ],
            'tokens' => $this->jwtService->generateTokenPair($user),
        ];
    }
}
