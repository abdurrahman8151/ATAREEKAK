<?php

namespace App\Services;

use App\Domain\ValueObjects\PhoneNumber;
use App\Interfaces\OtpRepositoryInterface;
use App\Models\Otp;
use App\Support\OtpDisclosure;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class WhatsAppOtpService
{
    protected $otpRepository;

    protected $client;

    protected $apiKey;

    public function __construct(OtpRepositoryInterface $otpRepository)
    {
        $this->otpRepository = $otpRepository;
        $this->client = new Client;
        // RV-28: read via config (not env()) — env() outside config/ returns null once
        // `php artisan config:cache` runs, so the API key would silently vanish in
        // production. Owned at config/services.php → callmebot.api_key.
        $this->apiKey = config('services.callmebot.api_key');
    }

    /**
     * Send OTP via WhatsApp
     */
    public function sendOtp(string $phoneNumber, string $type = 'E-PAYMENT'): array
    {
        // RV-16: WALLET_OTP_MODE=testing alone must not disclose a live code;
        // see OtpDisclosure. Whole path sanitized so no return can bypass it.
        return OtpDisclosure::sanitize($this->dispatchOtp($phoneNumber, $type));
    }

    private function dispatchOtp(string $phoneNumber, string $type = 'E-PAYMENT'): array
    {
        try {
            $validatedPhone = $this->validateSyrianPhone($phoneNumber);

            if (! $this->canSendOtp($validatedPhone)) {
                return [
                    'success' => false,
                    'message' => 'Too many OTP requests. Please try again later.',
                ];
            }

            $this->otpRepository->deleteByPhone($validatedPhone);

            $otpCode = Otp::generateCode();

            $otp = $this->otpRepository->create([
                'phone_number' => $validatedPhone,
                'otp_code' => $otpCode,
                'type' => $type,
                'expires_at' => Carbon::now()->addMinutes(10),
                'is_verified' => false,
                'attempts' => 0,
            ]);

            // ── TESTING MODE ─────────────────────────────────────────────────────
            // FIX: also check app()->environment('testing') so phpunit runs always
            // land here regardless of config-cache state on the very first request.
            if ($this->isTestingMode()) {
                Log::info("OTP (testing mode) for $validatedPhone: $otpCode");

                return [
                    'success' => true,
                    'message' => 'OTP generated (testing mode — use the code below)',
                    'otp_code' => $otpCode,
                    'expires_at' => $otp->expires_at->toDateTimeString(),
                ];
            }

            // ── PRODUCTION MODE ───────────────────────────────────────────────────
            if (empty($this->apiKey)) {
                return [
                    'success' => false,
                    'message' => 'No API key configured for production OTP sending.',
                ];
            }

            $sent = $this->sendViaCallMeBot($validatedPhone, $otpCode);

            if (! $sent) {
                Log::error("Failed to send OTP to $validatedPhone");

                return [
                    'success' => false,
                    'message' => 'Failed to send OTP. Please try again.',
                ];
            }

            Log::info("OTP sent (production) to $validatedPhone");

            return [
                'success' => true,
                'message' => 'OTP sent successfully via WhatsApp',
                'expires_at' => $otp->expires_at->toDateTimeString(),
            ];
        } catch (\Exception $e) {
            Log::error('OTP send error: '.$e->getMessage());

            return ['success' => false, 'message' => 'Failed to send OTP. Please try again.'];
        }
    }

    /**
     * Verify OTP
     */
    public function verifyOtp(string $phoneNumber, string $code): array
    {
        try {
            $validatedPhone = $this->validateSyrianPhone($phoneNumber);

            // Look the issued code up WITHOUT filtering on the guessed value.
            // Filtering by code made a wrong guess return null, so no attempt was
            // ever recorded and Otp::isValid()'s 3-attempt cap was unreachable.
            $otp = $this->otpRepository->findLatestByPhone($validatedPhone);

            if (! $otp) {
                return ['success' => false, 'message' => 'Invalid or expired OTP'];
            }

            if (! $otp->isValid()) {
                return ['success' => false, 'message' => 'OTP has expired or exceeded maximum attempts'];
            }

            if (! $otp->matchesCode($code)) {
                $otp->registerFailedAttempt();

                return ['success' => false, 'message' => 'Invalid or expired OTP'];
            }

            $otp->markAsVerified();

            return [
                'success' => true,
                'message' => 'OTP verified successfully',
                'data' => [
                    'phone_number' => $validatedPhone,
                    'verified_at' => $otp->verified_at->toDateTimeString(),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('OTP verification error: '.$e->getMessage());

            return ['success' => false, 'message' => 'OTP verification failed'];
        }
    }

    // ── private helpers ───────────────────────────────────────────────────────────

    /**
     * FIX: also check app()->environment('testing') so the first request in a
     * phpunit process always enters testing mode even when the config cache has
     * a stale WALLET_OTP_MODE value from a previous production cache run.
     */
    private function isTestingMode(): bool
    {
        // RV-37: read from config rather than the raw environment. A putenv() in one
        // test used to leak into every test that ran after it — the order-dependence
        // V14 measured (53 failures in default order, 55 under --order-by=random).
        // Config is rebuilt per test, so an override cannot escape the test that made
        // it. The environment gate below is unchanged: OtpDisclosure (RV-16) is what
        // stops a code leaving outside local/testing. See config/otp.php.
        return config('otp.wallet_mode') === 'testing'
            || app()->environment('testing');
    }

    /**
     * Check if bypass mode is enabled
     */
    private function isBypassMode(): bool
    {
        // RV-28: config('otp.bypass') already owns OTP_BYPASS_ENABLED; reading it via env()
        // here returned null once config was cached, silently disabling bypass. Use config.
        return config('otp.bypass', false)
            || app()->environment(['local', 'testing']);
    }

    /**
     * Validate if code is exactly 6 digits
     */
    private function isValidSixDigitCode(string $code): bool
    {
        return preg_match('/^\d{6}$/', $code);
    }

    /**
     * Create a dummy OTP record for bypass verification
     */
    private function createBypassOtpRecord(string $phoneNumber, string $code): void
    {
        try {
            $this->otpRepository->deleteByPhone($phoneNumber);

            $this->otpRepository->create([
                'phone_number' => $phoneNumber,
                'otp_code' => $code,
                'type' => 'BYPASS',
                'expires_at' => Carbon::now()->addHour(),
                'is_verified' => true,
                'verified_at' => Carbon::now(),
                'attempts' => 0,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to create bypass OTP record: '.$e->getMessage());
        }
    }

    /**
     * Send OTP via CallMeBot API (works with personal WhatsApp)
     */
    private function sendViaCallMeBot(string $phoneNumber, string $otpCode): bool
    {
        try {
            $normalizedPhone = $this->normalizeForCallMeBot($phoneNumber);

            $message = "Your verification code is: $otpCode\n\nThis code will expire in 5 minutes.\n\nDo not share this code with anyone.";

            $url = 'https://api.callmebot.com/whatsapp.php?'.http_build_query([
                'phone' => $normalizedPhone,
                'text' => $message,
                'apikey' => $this->apiKey,
            ]);

            // AF-1 (app-future audit): this built a SEPARATE Guzzle client with
            // ['verify' => false] — disabled TLS peer verification on an
            // endpoint that carries an API key. The class already owns a
            // properly-verified $this->client (constructor, line 21); the
            // insecure one existed only as a dev-box CA workaround. Verified
            // HTTPS to api.callmebot.com was proven working from this machine,
            // so the workaround had outlived its excuse.
            $response = $this->client->get($url);
            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();

            if ($statusCode === 200 || $statusCode === 203) {
                Log::info("OTP sent successfully to $phoneNumber. Response status: $statusCode");

                return true;
            }

            Log::error("CallMeBot failed. Status: $statusCode | Response: $responseBody");

            return false;
        } catch (RequestException $e) {
            Log::error('CallMeBot API error: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Normalize phone for CallMeBot (963XXXXXXXXX format)
     */
    private function normalizeForCallMeBot(string $phoneNumber): string
    {
        $clean = preg_replace('/[^0-9]/', '', $phoneNumber);
        $clean = ltrim($clean, '0');

        if (str_starts_with($clean, '9639') && strlen($clean) === 12) {
            return $clean;
        }

        if (str_starts_with($clean, '9') && strlen($clean) === 9) {
            return '963'.$clean;
        }

        return $clean;
    }

    /**
     * Validate Syrian phone number
     */
    private function validateSyrianPhone(string $phoneNumber): string
    {
        return (string) PhoneNumber::from($phoneNumber);
    }

    /**
     * Check if OTP can be sent (rate limiting)
     */
    private function canSendOtp(string $phoneNumber): bool
    {
        $recentAttempts = $this->otpRepository->getRecentAttempts($phoneNumber, 5);

        return $recentAttempts < 3;
    }
}
