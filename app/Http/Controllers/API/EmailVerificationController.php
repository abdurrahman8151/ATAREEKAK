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

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'No account found with this email.',
            ], 404);
        }

        if ($user->email_verified_at) {
            return response()->json([
                'success' => false,
                'message' => 'This email is already verified.',
            ], 409);
        }

        $dto = SendEmailOtpDTO::fromUser($user);
        $result = $this->emailOtpService->sendOtp($dto);

        return response()->json($result, $result['success'] ? 200 : 400);
    }
}
