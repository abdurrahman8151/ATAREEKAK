<?php

namespace App\Http\Controllers\API;

use App\Domain\ValueObjects\Email;
use App\DTOs\Auth\SendEmailOtpDTO;
use App\Http\Controllers\Controller;
use App\Interfaces\EmailOtpServiceInterface;
use App\Interfaces\UserRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * ForgotPasswordController
 *
 * Step 1 of the OTP-based password reset flow.
 * Sends a 6-digit OTP to the user's email (same mailer + template as signup).
 *
 * POST /api/password/forgot
 */
class ForgotPasswordController extends Controller
{
    public function __construct(
        private readonly EmailOtpServiceInterface $emailOtpService,
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255'],
        ], [
            'email.required' => 'Email address is required.',
            'email.email' => 'Please enter a valid email address.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = $request->input('email');
        $user = $this->userRepository->findByEmail($email);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'No account found with this email address.',
            ], 404);
        }

        $dto = new SendEmailOtpDTO(
            email: Email::from($email),
            userName: $user->first_name,
            type: 'PASSWORD_RESET',
        );

        $result = $this->emailOtpService->sendOtp($dto);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send the verification code. Please try again.',
            ], 500);
        }

        $response = [
            'success' => true,
            'message' => 'A 6-digit verification code has been sent to '.$email.'. It expires in 10 minutes.',
        ];

        // Expose OTP only in local/testing environments
        if (isset($result['otp_code'])) {
            $response['otp_code'] = $result['otp_code'];
        }

        return response()->json($response);
    }
}
