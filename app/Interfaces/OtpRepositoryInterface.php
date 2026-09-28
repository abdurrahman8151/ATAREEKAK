<?php

namespace App\Interfaces;

use App\Models\Otp;

interface OtpRepositoryInterface
{
    /**
     * Create a new OTP
     */
    public function create(array $data): Otp;

    /**
     * Find OTP by phone number and code
     */
    public function findByPhoneAndCode(string $phoneNumber, string $code): ?Otp;

    /**
     * Find active OTP by phone number and type
     */
    public function findActiveByPhoneAndType(string $phoneNumber, string $type): ?Otp;

    /**
     * Find the most recent OTP for a phone number, regardless of code,
     * verification state, expiry or attempt count.
     *
     * Verification has to locate the live code first and compare the guess
     * afterwards: filtering by code in the query makes a wrong guess return no
     * row at all, leaving nothing to record the attempt against.
     */
    public function findLatestByPhone(string $phoneNumber): ?Otp;

    /**
     * Delete expired OTPs
     */
    public function deleteExpired(): int;

    /**
     * Delete OTPs by phone number
     */
    public function deleteByPhone(string $phoneNumber): int;

    /**
     * Get recent OTP attempts for phone number
     */
    public function getRecentAttempts(string $phoneNumber, int $minutes = 5): int;
}
