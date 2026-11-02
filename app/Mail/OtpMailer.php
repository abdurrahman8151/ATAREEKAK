<?php

namespace App\Mail;

use App\Modules\Identity\Contracts\OtpMailInterface;
use Illuminate\Support\Facades\Mail;

/**
 * The COMM-side implementation of the OTP mail port (batch 233).
 *
 * This class exists only to move the `Mail` facade call out of `EmailOtpService`: the call below is
 * byte-for-byte the one that used to sit in `EmailOtpService::dispatchOtp()`, including the
 * synchronous (not queued) send and the argument order of `OtpVerificationMail`.
 */
final class OtpMailer implements OtpMailInterface
{
    public function send(string $to, string $otpCode, string $userName, int $expiryMinutes): void
    {
        Mail::to($to)->send(
            new OtpVerificationMail($otpCode, $userName, $expiryMinutes)
        );
    }
}
