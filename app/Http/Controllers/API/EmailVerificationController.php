<?php

namespace App\Http\Controllers\API;

use App\DTOs\Auth\SendEmailOtpDTO;
use App\DTOs\Auth\VerifyEmailOtpDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendEmailOtpRequest;
use App\Http\Requests\VerifyEmailOtpRequest;
use App\Interfaces\EmailOtpServiceInterface;
use App\Interfaces\UserRepositoryInterface;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class EmailVerificationController extends Controller
{
    public function __construct(
        private readonly EmailOtpServiceInterface $emailOtpService,
        private readonly UserRepositoryInterface $userRepository,
        private readonly JwtService $jwtService,
    ) {}

    /**
     * POST /api/email-verification/send
     */
    public function send(SendEmailOtpRequest $request): JsonResponse
    {
        $userName = $request->user()?->first_name ?? 'User';
        $dto = SendEmailOtpDTO::fromRequest($request->validated(), $userName);
        $result = $this->emailOtpService->sendOtp($dto);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * POST /api/email-verification/verify
     * Verifies OTP then returns JWT tokens
     */
    public function verify(VerifyEmailOtpRequest $request): JsonResponse
    {
        $dto = VerifyEmailOtpDTO::fromRequest($request->validated());
        $result = $this->emailOtpService->verifyOtp($dto);

        if (! $result['success']) {
            return response()->json($result, 400);
        }

        $user = User::where('email', $dto->email->address())->firstOrFail();
        $user->update(['email_verified_at' => now()]);
        $user->refresh();

        $tokens = $this->jwtService->generateTokenPair($user);

        return response()->json([
            'success' => true,
            'message' => 'Email verified. You are now logged in.',
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
                'is_verified_passenger' => $user->is_verified_passenger,
                'is_verified_driver' => $user->is_verified_driver,
            ],
            'tokens' => $tokens,
        ]);
    }

    /**
     * POST /api/email-verification/resend
     */
    public function resend(SendEmailOtpRequest $request): JsonResponse
    {
        $user = $this->userRepository->findByEmail($request->validated('email'));

        // Decision 8 (owner ruling A - uniform errors, no account enumeration).
        //
        // This endpoint had THREE distinguishable states: 404 "No account found", 409 "already
        // verified", and 200 on a real send. Removing only the 404 would NOT have closed the oracle -
        // 200 versus 409 still separates "exists and unverified" from "exists and verified", which is
        // the same information. So all three answer identically and only the send is conditional.
        //
        // That deliberately gives up the "This email is already verified." hint. It was a genuine
        // convenience, and it was also an account-existence oracle. Decision 8 rules it out.
        if (! $user) {
            return $this->uniformResendResponse();
        }

        if ($user->email_verified_at) {
            return $this->uniformResendResponse();
        }

        $dto = SendEmailOtpDTO::fromUser($user);
        $result = $this->emailOtpService->sendOtp($dto);

        if (! $result['success']) {
            // The real outcome is recorded, not returned. A failed send that answers differently
            // from a successful one is the same leak in a new place: it tells an attacker the
            // account exists AND that the mail path is broken.
            Log::warning('Verification resend failed', [
                'email' => $request->validated('email'),
                'reason' => $result['message'] ?? null,
            ]);
        }

        return $this->uniformResendResponse($result);
    }

    /**
     * The single response this endpoint gives, whatever state the address is in (decision 8).
     *
     * The wording is deliberately conditional: "If the address needs verification" is true on
     * every path, including the two where nothing was sent. A message that asserted a code had
     * been sent would be false twice out of three, and the fix would be to lie in the other
     * direction instead.
     *
     * `otp_code` is still passed through when the service returns one, exactly as the other send
     * endpoints do. That is NOT an enumeration channel: `OtpDisclosure` permits it in
     * local/testing only, and it can only ever be present on the branch that actually sent.
     * Dropping it was a real regression in the first draft of this change - it silently broke the
     * endpoint's own test tooling and the verify-after-resend flow.
     */
    private function uniformResendResponse(?array $result = null): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => 'If the address needs verification, a code has been sent. It expires in 10 minutes.',
        ];

        if ($result !== null && isset($result['otp_code'])) {
            $response['otp_code'] = $result['otp_code'];
        }

        return response()->json($response);
    }
}
