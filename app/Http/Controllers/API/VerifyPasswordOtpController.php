<?php

namespace App\Http\Controllers\API;

use App\DTOs\Auth\VerifyEmailOtpDTO;
use App\Http\Controllers\Controller;
use App\Interfaces\EmailOtpServiceInterface;
use App\Interfaces\UserRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            // Decision 8 (owner ruling A - uniform errors, no account enumeration): the
            // `exists:users,email` rule used to sit here, so an unknown address answered
            // 422 with "No account found with this email." while a known address with a wrong code
            // answered 400 "Invalid or expired code." Two shapes, one bit of truth about who has
            // an account. The lookup is done below instead, and both outcomes answer identically.
            'email' => ['required', 'string', 'email', 'max:255'],
            'otp_code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ], [
            'otp_code.size' => 'The code must be exactly 6 digits.',
            'otp_code.regex' => 'The code must contain numbers only.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $knownAccount = $this->userRepository->findByEmail($request->input('email')) !== null;

        if (! $knownAccount) {
            // Deliberately the same 400 the failed-code branch below returns.
            return $this->invalidCodeResponse();
        }

        // Reuse the same DTO + service as the email verification flow
        $dto = VerifyEmailOtpDTO::fromRequest($validator->validated());
        $result = $this->emailOtpService->verifyOtp($dto);

        if (! $result['success']) {
            // The reason is recorded, never returned. Passing `$result['message']` straight
            // through is what made the unknown-account branch distinguishable when it used its
            // own wording: two different phrasings of "that code did not work" is still an
            // oracle. The first draft of this fix used 'Invalid or expired code.' against the
            // service's 'Invalid or expired verification code.' and leaked anyway - which the
            // uniformity test caught.
            Log::warning('Password OTP verification failed', [
                'email' => $request->input('email'),
                'reason' => $result['message'] ?? null,
            ]);

            return $this->invalidCodeResponse();
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

    /**
     * The one answer a rejected code gets, whatever the real reason (decision 8).
     *
     * "Unknown account" and "wrong code" must be the same bytes on the wire. The reason is logged
     * by the caller instead, so nothing is lost operationally.
     */
    private function invalidCodeResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Invalid or expired code.',
        ], 400);
    }
}
