<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\UserRepositoryInterface;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class LoginController extends Controller
{
    private UserRepositoryInterface $userRepository;

    private JwtService $jwtService;

    public function __construct(
        UserRepositoryInterface $userRepository,
        JwtService $jwtService
    ) {
        $this->userRepository = $userRepository;
        $this->jwtService = $jwtService;
    }

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
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid credentials',
                'code' => 'INVALID_CREDENTIALS',
            ], 401);
        }

        // ── Verify password ───────────────────────────────────────────────────
        if (! Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid credentials',
                'code' => 'INVALID_CREDENTIALS',
            ], 401);
        }

        // ── Block unverified email ────────────────────────────────────────────
        // Users who registered but never submitted the OTP are blocked here.
        // email_verified_at is set by EmailVerificationController::verify()
        // only after the correct OTP is submitted.
        if (! $user->email_verified_at) {
            return response()->json([
                'status' => 'error',
                'message' => 'Your email address is not verified. '
                    .'Please check your inbox for the verification code.',
                'code' => 'EMAIL_NOT_VERIFIED',
                'email' => $user->email,
            ], 403);
        }

        // ── Block banned accounts ─────────────────────────────────────────────
        // RV-12: honour ban_expires_at. The middleware auto-lifts expired bans, but
        // it only runs for AUTHENTICATED requests, and a banned user's tokens were
        // revoked at ban time — login was the only remaining door, and it ignored
        // the expiry, so an expired TEMPORARY ban locked the user out forever.
        // isBannedNow() is the single source of truth (status -1 AND not expired).
        if ($user->isBannedNow()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Your account has been suspended. Please contact support.',
                'code' => 'ACCOUNT_BANNED',
            ], 403);
        }

        if ($user->banHasExpired()) {
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

        // ── All checks passed — issue tokens ──────────────────────────────────
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
