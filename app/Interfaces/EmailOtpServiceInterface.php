<?php

namespace App\Interfaces;

use App\DTOs\Auth\SendEmailOtpDTO;
use App\DTOs\Auth\VerifyEmailOtpDTO;

interface EmailOtpServiceInterface
{
    public function sendOtp(SendEmailOtpDTO $dto): array;

    public function verifyOtp(VerifyEmailOtpDTO $dto): array;
}
