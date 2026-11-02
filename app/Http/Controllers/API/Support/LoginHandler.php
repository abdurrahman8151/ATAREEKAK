<?php

namespace App\Http\Controllers\API\Support;

use App\Modules\Identity\Contracts\UserRepositoryInterface;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Login action for LoginController, moved out verbatim.
 *
 * LoginController keeps __invoke with the same signature and delegates here, so the route binding and
 * the response shape are unchanged. The body is the original one: the 422 validation, the 401
 * INVALID_CREDENTIALS refusal (identical for an unknown email and a wrong password), the 403
 * EMAIL_NOT_VERIFIED and ACCOUNT_BANNED refusals, the expired-ban clearing, and the 200 token issue are
 * all kept exactly.
 */
class LoginHandler
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly JwtService $jwtService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // ── Validate ──────────────────────────────────────────────────────────
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // ── Find user ─────────────────────────────────────────────────────────
        $user = $this->userRepository->findByEmail($request->email);

        if (! $user) {
            return $this->invalidCredentialsResponse();
        }

        // ── Verify password ───────────────────────────────────────────────────
        if (! Hash::check($request->password, $user->password)) {
            return $this->invalidCredentialsResponse();
        }

        $refusal = $this->refuseUnverifiedEmail($user);
        if ($refusal !== null) {
            return $refusal;
        }

        $refusal = $this->refuseBannedAccount($user);
        if ($refusal !== null) {
            return $refusal;
        }

        $this->liftExpiredBan($user);

        // ── All checks passed — issue tokens ──────────────────────────────────
        return $this->issueTokens($user);
    }

    /**
     * The one 401 refusal shared by "no such email" and "wrong password".
     *
     * Keeping a single body is deliberate: the two cases must be indistinguishable.
     */
    private function invalidCredentialsResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Invalid credentials',
            'code' => 'INVALID_CREDENTIALS',
        ], 401);
    }

    /**
     * The EMAIL_NOT_VERIFIED refusal, or null when the address is verified.
     */
    private function refuseUnverifiedEmail($user): ?JsonResponse
    {
        // ── Block unverified email ────────────────────────────────────────────
        // Users who registered but never submitted the OTP are blocked here.
        // email_verified_at is set by EmailVerificationController::verify()
        // only after the correct OTP is submitted.
        if ($user->email_verified_at) {
            return null;
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Your email address is not verified. '
                .'Please check your inbox for the verification code.',
            'code' => 'EMAIL_NOT_VERIFIED',
            'email' => $user->email,
        ], 403);
    }

    /**
     * The ACCOUNT_BANNED refusal, or null when the account is not banned right now.
     */
    private function refuseBannedAccount($user): ?JsonResponse
    {
        // ── Block banned accounts ─────────────────────────────────────────────
        // RV-12: honour ban_expires_at. The middleware auto-lifts expired bans, but
        // it only runs for AUTHENTICATED requests, and a banned user's tokens were
        // revoked at ban time — login was the only remaining door, and it ignored
        // the expiry, so an expired TEMPORARY ban locked the user out forever.
        // isBannedNow() is the single source of truth (status -1 AND not expired).
        if (! $user->isBannedNow()) {
            return null;
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Your account has been suspended. Please contact support.',
            'code' => 'ACCOUNT_BANNED',
        ], 403);
    }

    /**
     * Clear the dead ban columns when the temporary ban has already expired.
     */
    private function liftExpiredBan($user): void
    {
        if (! $user->banHasExpired()) {
            return;
        }

        // The ban is no longer in force: clear the dead ban fields so the row
        // cannot be mistaken for an active ban by anything reading it, exactly
        // as the middleware's auto-lift does.
        $user->update([
            'ban_reason' => null,
            'ban_type' => null,
            'banned_at' => null,
            'ban_expires_at' => null,
            'banned_by' => null,
        ]);
    }

    /**
     * Flip the account active and hand back the 200 token body.
     */
    private function issueTokens($user): JsonResponse
    {
        $this->userRepository->updateUserStatus($user->id, 1);

        $user->refresh();

        $tokens = $this->jwtService->generateTokenPair($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'gender' => $user->gender,
                'address' => $user->address,
                'status' => $user->status,
                'is_verified_passenger' => $user->is_verified_passenger,
                'is_verified_driver' => $user->is_verified_driver,
                'verification_status' => $user->verification_status,
                'created_at' => $user->created_at->toDateTimeString(),
            ],
            'tokens' => $tokens,
        ], 200);
    }
}
