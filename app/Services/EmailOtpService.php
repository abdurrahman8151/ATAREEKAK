<?php

namespace App\Services;

use App\DTOs\Auth\SendEmailOtpDTO;
use App\DTOs\Auth\VerifyEmailOtpDTO;
use App\Interfaces\EmailOtpServiceInterface;
use App\Interfaces\OtpRepositoryInterface;
use App\Mail\OtpVerificationMail;
use App\Models\Otp;
use App\Support\OtpDisclosure;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class EmailOtpService implements EmailOtpServiceInterface
{
    private const EXPIRY_MINUTES = 10;

    private const MAX_ATTEMPTS = 3;

    private const RATE_LIMIT_WINDOW = 5;

    public function __construct(
        private readonly OtpRepositoryInterface $otpRepository,
    ) {}

    public function sendOtp(SendEmailOtpDTO $dto): array
    {
        // RV-16: the whole send path is sanitized, so `otp_code` cannot escape
        // outside local/testing. See OtpDisclosure for why this is a choke point
        // rather than a check at each return.
        return OtpDisclosure::sanitize($this->dispatchOtp($dto));
    }

    private function dispatchOtp(SendEmailOtpDTO $dto): array
    {
        try {
            $identifier = $dto->email->address();

            if ($this->isRateLimited($identifier)) {
                return [
                    'success' => false,
                    'message' => 'Too many requests. Please wait a few minutes.',
                ];
            }

            $this->otpRepository->deleteByPhone($identifier);

            $otpCode = Otp::generateCode();

            $otp = $this->otpRepository->create([
                'phone_number' => $identifier,
                'otp_code' => $otpCode,
                'type' => $dto->type,
                'expires_at' => Carbon::now()->addMinutes(self::EXPIRY_MINUTES),
                'is_verified' => false,
                'attempts' => 0,
            ]);

            if ($this->isTestingMode()) {
                Log::info("Email OTP (testing) for {$identifier}: {$otpCode}");

                return [
                    'success' => true,
                    'message' => 'OTP generated (testing mode).',
                    'otp_code' => $otpCode,
                    'expires_at' => $otp->expires_at->toDateTimeString(),
                ];
            }

            Mail::to($identifier)->send(
                new OtpVerificationMail($otpCode, $dto->userName, self::EXPIRY_MINUTES)
            );

            Log::info("Email OTP sent to {$identifier}");

            return [
                'success' => true,
                'message' => 'Verification code sent to your email.',
                'expires_at' => $otp->expires_at->toDateTimeString(),
            ];

        } catch (\Exception $e) {
            Log::error("EmailOtpService::sendOtp failed: {$e->getMessage()}");

            return [
                'success' => false,
                'message' => 'Failed to send verification email. Please try again.',
            ];
        }
    }

    public function verifyOtp(VerifyEmailOtpDTO $dto): array
    {
        try {
            $identifier = $dto->email->address();

            // Look the issued code up WITHOUT filtering on the guessed value.
            // Filtering by code made a wrong guess return null, so there was
            // nothing to record the attempt against and the 3-attempt cap in
            // Otp::isValid() could never be reached — a 6-digit code stayed
            // brute-forceable for its whole 10-minute lifetime.
            $otp = $this->otpRepository->findLatestByPhone($identifier);

            if (! $otp || ! $otp->isValid()) {
                return [
                    'success' => false,
                    'message' => 'Invalid or expired verification code.',
                ];
            }

            if (! $otp->matchesCode($dto->otpCode)) {
                // Count the miss; once the cap is hit the row is no longer
                // isValid() and the code is dead even if later guessed correctly.
                $otp->registerFailedAttempt();

                return [
                    'success' => false,
                    'message' => 'Invalid or expired verification code.',
                ];
            }

            $otp->markAsVerified();

            return [
                'success' => true,
                'message' => 'Email verified successfully.',
                'verified_at' => $otp->verified_at->toDateTimeString(),
            ];

        } catch (\Exception $e) {
            Log::error("EmailOtpService::verifyOtp failed: {$e->getMessage()}");

            return ['success' => false, 'message' => 'Verification failed.'];
        }
    }

    private function isRateLimited(string $identifier): bool
    {
        return $this->otpRepository->getRecentAttempts(
            $identifier,
            self::RATE_LIMIT_WINDOW
        ) >= self::MAX_ATTEMPTS;
    }

    private function isTestingMode(): bool
    {
        // env() resolves via $_ENV/$_SERVER first, which are populated once at
        // boot from .env and are NOT updated by a later putenv() call (e.g. in
        // a test's setUp()). getenv() reads the live process env table directly,
        // which putenv() does update immediately — check it first.
        return getenv('EMAIL_OTP_MODE') === 'testing'
            || env('EMAIL_OTP_MODE', 'production') === 'testing';
    }
}
