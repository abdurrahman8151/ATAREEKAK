<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
            return response()->json([
                'success' => false,
                'message' => 'This reset link has expired or has already been used. Please request a new code.',
            ], 400);
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            // Defensive: should not happen if cache key is intact
            Cache::forget($cacheKey);

            return response()->json([
                'success' => false,
                'message' => 'Account not found.',
            ], 404);
        }

        // Update password
        // Update password
        $user->password = Hash::make($request->input('password'));
        $user->save();

        // Consume the token — it must not be reusable
        Cache::forget($cacheKey);

        // Revoke all JWT tokens (increments token_version + deletes refresh tokens)
        app(JwtService::class)->revokeAllTokens($user->id);
        Log::info('Password reset via OTP flow', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Your password has been reset successfully. You can now log in.',
        ]);
    }
}
