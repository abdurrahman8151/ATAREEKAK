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

        // Decision 8 (owner ruling A - "uniform errors, no account enumeration").
        //
        // This used to answer 404 "No account found with this email address." for an unknown
        // address and 200 for a known one. Two different statuses and two different messages is
        // a perfect oracle: POST a list of candidate addresses and keep the ones that do not 404.
        // That tells an attacker which addresses are registered here - personal data, and a
        // reliable list to aim credential stuffing or phishing at.
        //
        // So the response is IDENTICAL either way and only the send is conditional. The remaining
        // difference - whether an email actually arrives - is inherent to the feature and is not
        // observable from the HTTP response. A real account must still get its code, so the
        // success branch is left exactly as it was.
        if (! $user) {
            return response()->json([
                'success' => true,
                'message' => 'A 6-digit verification code has been sent to '.$email.'. It expires in 10 minutes.',
            ]);
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
