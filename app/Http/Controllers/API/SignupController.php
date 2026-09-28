<?php

namespace App\Http\Controllers\API;

use App\DTOs\Auth\SendEmailOtpDTO;
use App\Http\Controllers\Controller;
use App\Interfaces\EmailOtpServiceInterface;
use App\Interfaces\UserRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SignupController extends Controller
{
    public function __construct(
        private readonly UserRepositoryInterface  $userRepository,
        private readonly EmailOtpServiceInterface $emailOtpService,
    ) {}

    public function register(Request $request): JsonResponse
    {
        // ── Validation ────────────────────────────────────────────────────────
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => 'required|email|max:255',
            'password'   => 'required|string|confirmed|min:8',
            'gender'     => 'required|in:M,F',
            'address'    => 'required|in:دمشق,درعا,القنيطرة,السويداء,ريف دمشق,حمص,حماة,اللاذقية,طرطوس,حلب,ادلب,الحسكة,الرقة,دير الزور',
        ], [
            'password.confirmed' => 'Password confirmation does not match.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // ── Check for existing account ────────────────────────────────────────
        try {
            $existingUser = $this->userRepository->findByEmail($request->email);
        } catch (\Throwable $e) {
            Log::error('Signup: DB lookup failed', [
                'email' => $request->email,
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            return $this->serverError($e);
        }

        // ── PATH A: email exists ──────────────────────────────────────────────
        if ($existingUser) {

            // Already fully verified → reject
            if ($existingUser->email_verified_at !== null) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'This email address is already registered. Please log in.',
                ], 409);
            }

            // Unverified → resend the verification code ONLY.
            //
            // This branch used to overwrite the existing account's password with
            // the request-supplied value. Nothing here proves the caller controls
            // the mailbox, so anyone who knew an abandoned (unverified) address
            // could set that account's password to a value of their choosing —
            // a pre-account-takeover: the victim's own password stops working and
            // the attacker's chosen one becomes the live credential.
            //
            // Requesting a new code must not be a credential-change primitive.
            // The password is left untouched; it can only be changed through the
            // OTP-verified reset flow under /api/auth/password/*.
            //
            // The response is deliberately neutral: it echoes no account identity
            // (id/first_name/email) and no otp_code. The dev affordance for the
            // code is the existing Log::info in EmailOtpService::sendOtp().
            try {
                $dto       = SendEmailOtpDTO::fromUser($existingUser);
                $otpResult = $this->emailOtpService->sendOtp($dto);

                if (!$otpResult['success']) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Could not send verification email. Please try again.',
                    ], 500);
                }

                return response()->json([
                    'status'  => 'success',
                    'message' => 'A new verification code has been sent to your email.',
                ], 200);

            } catch (\Throwable $e) {
                Log::error('Signup: resend OTP failed (Path A)', [
                    'user_id' => $existingUser->id,
                    'email'   => $existingUser->email,
                    'error'   => $e->getMessage(),
                    'class'   => get_class($e),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ]);
                return $this->serverError($e);
            }
        }

        // ── PATH B: new user ──────────────────────────────────────────────────
        DB::beginTransaction();
        try {
            $user = $this->userRepository->createUser([
                'first_name' => $request->first_name,
                'last_name'  => $request->last_name,
                'email'      => $request->email,
                'password'   => Hash::make($request->password),
                'gender'     => $request->gender,
                'address'    => $request->address,
                // FIX: was 1 (active) — user must verify email before they can
                // log in. LoginController now enforces email_verified_at, but
                // starting at 0 adds a second layer of defence.
                'status'     => 0,
            ]);

            $dto       = SendEmailOtpDTO::fromUser($user);
            $otpResult = $this->emailOtpService->sendOtp($dto);

            if (!$otpResult['success']) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Registration failed: could not send verification email.',
                ], 500);
            }

            DB::commit();

            // NOTE: the response intentionally does NOT include otp_code.
            // Echoing the verification code in an API response turns any
            // EMAIL_OTP_MODE misconfiguration (or a non-production default) into
            // an instant account takeover. The code is delivered by email only;
            // the dev affordance is the Log::info in EmailOtpService::sendOtp().
            return response()->json([
                'status'  => 'success',
                'message' => 'Registration successful. Check your email for a verification code.',
                'user'    => [
                    'id'         => $user->id,
                    'first_name' => $user->first_name,
                    'email'      => $user->email,
                ],
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Registration failed (Path B)', [
                'email' => $request->email,
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);
            return $this->serverError($e);
        }
    }

    private function serverError(\Throwable $e): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => 'Registration failed',
            'error'   => config('app.debug') ? $e->getMessage() : 'An error occurred',
        ], 500);
    }
}
