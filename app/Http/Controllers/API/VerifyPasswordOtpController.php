<?php

namespace App\Http\Controllers\API;

use App\DTOs\Auth\VerifyEmailOtpDTO;
use App\Http\Controllers\Controller;
use App\Interfaces\EmailOtpServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * VerifyPasswordOtpController
 *
 * Step 2 of the OTP-based password reset flow.
 *
 * Verifies the 6-digit code the user received by email.
 * On success it stores a short-lived, single-use `reset_token` in the cache
 * and returns it to the client.  The client must include this token in the
 * subsequent POST /api/password/reset request.
 *
 * POST /api/password/verify-otp
 *
 * Request body:
 *   { "email": "user@example.com", "otp_code": "123456" }
 *
 * Success response:
 *   { "success": true, "message": "...", "reset_token": "<uuid>" }
 */
class VerifyPasswordOtpController extends Controller
{
    /** Cache key prefix – keeps password-reset tokens isolated from other cache entries. */
    private const CACHE_PREFIX = 'pwd_reset_token:';

    /** How long (seconds) the reset_token remains valid after OTP verification. */
    private const TOKEN_TTL = 900; // 15 minutes

    public function __construct(
        private readonly EmailOtpServiceInterface $emailOtpService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'exists:users,email'],
            'otp_code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ], [
            'email.exists' => 'No account found with this email.',
            'otp_code.size' => 'The code must be exactly 6 digits.',
            'otp_code.regex' => 'The code must contain numbers only.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Reuse the same DTO + service as the email verification flow
        $dto = VerifyEmailOtpDTO::fromRequest($validator->validated());
        $result = $this->emailOtpService->verifyOtp($dto);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Invalid or expired code.',
            ], 400);
        }

        /*
         * OTP is valid.
         * Issue a single-use UUID token the client must present at /api/password/reset.
         * The token maps to the verified email so the reset step needs no OTP re-check.
         */
        $resetToken = (string) Str::uuid();

        Cache::put(
            self::CACHE_PREFIX.$resetToken,
            $request->input('email'),
            self::TOKEN_TTL
        );

        return response()->json([
            'success' => true,
            'message' => 'Code verified. You may now set a new password.',
            'reset_token' => $resetToken,
            'expires_in' => self::TOKEN_TTL, // seconds – useful for frontend countdown
        ]);
    }

    /**
     * Public helper so ResetPasswordController can share the same cache prefix constant
     * without coupling the two classes tightly.
     */
    public static function cacheKey(string $token): string
    {
        return self::CACHE_PREFIX.$token;
    }
}
