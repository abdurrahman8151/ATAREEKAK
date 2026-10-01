<?php

namespace App\Repositories;

use App\Interfaces\OtpRepositoryInterface;
use App\Models\Otp;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OtpRepository implements OtpRepositoryInterface
{
    protected $model;

    public function __construct(Otp $otp)
    {
        $this->model = $otp;
    }

    /**
     * Create a new OTP
     */
    public function create(array $data): Otp
    {
        return $this->model->create($data);
    }

    /**
     * Find OTP by phone number and code
     *
     * RV-16: `otp_code` is now stored as an HMAC, so the equality lookup hashes the
     * supplied code with the same key (the model mutator owns the storage form; this is
     * its mirror for the query side). NOTE: the OTP services do NOT use this anymore —
     * they use findLatestByPhone() + the constant-time Otp::matchesCode() so a wrong
     * guess still reaches the row to be counted. This exact-match variant stays only to
     * honour the interface contract; it is deliberately digest-aware, never plaintext.
     */
    public function findByPhoneAndCode(string $phoneNumber, string $code): ?Otp
    {
        return DB::transaction(function () use ($phoneNumber, $code) {
            return $this->model
                ->where('phone_number', $phoneNumber)
                ->where('otp_code', Otp::hashCode($code))
                ->active()
                ->first();
        });
    }

    /**
     * Find active OTP by phone number and type
     */
    public function findActiveByPhoneAndType(string $phoneNumber, string $type): ?Otp
    {
        return $this->model
            ->where('phone_number', $phoneNumber)
            ->where('type', $type)
            ->active()
            ->first();
    }

    /**
     * Find the most recent OTP for a phone number, regardless of code,
     * verification state, expiry or attempt count.
     *
     * Callers must still gate on Otp::isValid() before honouring it — the point
     * is that a *wrong* guess can reach the row and record its attempt.
     */
    public function findLatestByPhone(string $phoneNumber): ?Otp
    {
        return $this->model
            ->where('phone_number', $phoneNumber)
            ->latest('id')
            ->first();
    }

    /**
     * Delete expired OTPs
     */
    public function deleteExpired(): int
    {
        return $this->model->expired()->delete();
    }

    /**
     * Delete OTPs by phone number
     */
    public function deleteByPhone(string $phoneNumber): int
    {
        return $this->model
            ->where('phone_number', $phoneNumber)
            ->delete();
    }

    /**
     * Get recent OTP attempts for phone number
     */
    public function getRecentAttempts(string $phoneNumber, int $minutes = 5): int
    {
        return $this->model
            ->where('phone_number', $phoneNumber)
            ->where('created_at', '>=', Carbon::now()->subMinutes($minutes))
            ->count();
    }
}
