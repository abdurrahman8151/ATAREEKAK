<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

/**
 * ResetPasswordController
 *
 * Step 3 (final) of the OTP-based password reset flow.
 *
 * Accepts the `reset_token` returned by VerifyPasswordOtpController,
 * looks up the associated email in the cache, and updates the password.
 *
 * The token is consumed (deleted) immediately after a successful reset
 * so it cannot be reused.
 *
 * POST /api/password/reset
 *
 * Request body:
 *   {
 *     "reset_token":            "<uuid from verify-otp step>",
 *     "password":               "newSecret123",
 *     "password_confirmation":  "newSecret123"
 *   }
 */
class ResetPasswordController extends Controller
{
    public function __construct(private readonly PasswordResetService $passwordResetService) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reset_token' => ['required', 'string', 'uuid'],
            'password' => ['required', 'string', 'confirmed', 'min:8'],
            'password_confirmation' => ['required', 'string'],
        ], [
            'reset_token.required' => 'A valid reset token is required.',
            'reset_token.uuid' => 'The reset token format is invalid.',
            'password.confirmed' => 'Password confirmation does not match.',
            'password.min' => 'Password must be at least 8 characters.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Retrieve the email that was stored when the OTP was verified
        $cacheKey = VerifyPasswordOtpController::cacheKey($request->input('reset_token'));
        $email = Cache::get($cacheKey);

        if (! $email) {
            return $this->expiredLinkResponse();
        }

        $applied = $this->passwordResetService->resetForEmail(
            $email,
            (string) $request->input('password'),
            $cacheKey,
            $request->ip(),
        );

        if (! $applied) {
            // Decision 8 (owner ruling A - uniform errors, no account enumeration): "Account not
            // found." was a third, distinct way of saying the same thing, and a distinct response is
            // exactly what makes a difference readable. Answer with the SAME 400 the
            // expired-or-used branch above already returns.
            Cache::forget($cacheKey);

            return $this->expiredLinkResponse();
        }

        return response()->json([
            'success' => true,
            'message' => 'Your password has been reset successfully. You can now log in.',
        ]);
    }

    /**
     * The one 400 that "no token", "expired/used token" and "no such account" all share.
     */
    private function expiredLinkResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'This reset link has expired or has already been used. Please request a new code.',
        ], 400);
    }
}
