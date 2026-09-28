<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

final class OtpVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly string $otpCode,
        private readonly string $userName,
        private readonly int $expiryMinutes = 10,
    ) {}

    // app/Mail/OtpVerificationMail.php

    public function build(): self
    {
        return $this
            ->subject('Your Atarikak Verification Code')
            ->view('emails.otp-verification')
            ->with([                          // ← ADD THIS
                'otpCode' => $this->otpCode,
                'userName' => $this->userName,
                'expiryMinutes' => $this->expiryMinutes,
            ]);
    }
}
