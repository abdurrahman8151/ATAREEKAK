<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Otp extends Model
{
    use HasFactory;

    /** Verification attempts allowed per issued code before it is burned. */
    public const MAX_ATTEMPTS = 3;

    protected $fillable = [
        'phone_number',
        'otp_code',
        'type',
        'expires_at',
        'is_verified',
        'verified_at',
        'attempts'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'is_verified' => 'boolean',
    ];

    /**
     * Check if OTP is expired
     */
    public function isExpired(): bool
    {
        return Carbon::now()->gt($this->expires_at);
    }

    /**
     * Check if OTP is valid
     */
    public function isValid(): bool
    {
        return !$this->is_verified && !$this->isExpired() && $this->attempts < self::MAX_ATTEMPTS;
    }

    /**
     * Does the supplied code match this OTP?
     *
     * Compared in constant time so verification does not leak the code through
     * response timing. This is also why the code is no longer matched inside the
     * lookup query: a wrong guess has to reach the row so the attempt is counted.
     */
    public function matchesCode(string $code): bool
    {
        return hash_equals((string) $this->otp_code, $code);
    }

    /**
     * Record a failed verification attempt and report whether the code is burned.
     *
     * @return bool true while further attempts are still permitted
     */
    public function registerFailedAttempt(): bool
    {
        $this->incrementAttempts();

        return $this->attempts < self::MAX_ATTEMPTS;
    }

    /**
     * Mark OTP as verified
     */
    public function markAsVerified(): void
    {
        $this->update([
            'is_verified' => true,
            'verified_at' => Carbon::now()
        ]);
    }

    /**
     * Increment attempts
     */
    public function incrementAttempts(): void
    {
        $this->increment('attempts');
    }

    /**
     * Generate a random OTP code
     */
    public static function generateCode(): string
    {
        return str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Scope for active OTPs
     */
    public function scopeActive($query)
    {
        return $query->where('is_verified', false)
            ->where('expires_at', '>', Carbon::now())
            ->where('attempts', '<', self::MAX_ATTEMPTS);
    }

    /**
     * Scope for expired OTPs
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', Carbon::now());
    }
}
