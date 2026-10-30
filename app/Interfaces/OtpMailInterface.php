<?php

namespace App\Interfaces;

/**
 * Port over the OTP verification e-mail (batch 233).
 *
 * IDENTITY generates and stores the OTP; COMM owns the Mailable that carries it. Before this port
 * `EmailOtpService` sent `App\Mail\OtpVerificationMail` inline, which is the one IDENTITY -> COMM
 * edge the notification port did not cover. Implemented by `App\Mail\OtpMailer`, which makes the
 * exact same `Mail::to(...)->send(new OtpVerificationMail(...))` call the service made itself.
 */
interface OtpMailInterface
{
    /** Queue nothing, wait for nothing more than the original send did: one synchronous send. */
    public function send(string $to, string $otpCode, string $userName, int $expiryMinutes): void;
}
