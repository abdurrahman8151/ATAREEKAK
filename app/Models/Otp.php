<?php

namespace App\Models;

use App\Models\Concerns\GuardsLazyLoading;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Otp extends Model
{
    // RV-38: arms Eloquent's lazy-loading guard on every hydrated instance.
    use GuardsLazyLoading;
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
        'attempts',
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
        return ! $this->is_verified && ! $this->isExpired() && $this->attempts < self::MAX_ATTEMPTS;
    }

    /**
     * RV-16: a 6-digit code is LOW-ENTROPY (10^6 combinations) and must not be stored in
     * plaintext: a leaked DB/backup would hand an attacker every live code. `hash_hmac`
     * keyed by the app secret is exactly what §22.4 prescribes. Deliberately NOT bcrypt —
     * codes live minutes, are single-use, and the model already caps MAX_ATTEMPTS, so the
     * goal is "a DB-only leak cannot reveal the code", not offline-crash resistance against
     * an attacker who also holds APP_KEY (who can brute any 6-digit HMAC instantly —
     * accepted, and no weaker than the plaintext it replaces).
     */
    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /**
     * Store the code hashed. This mutator is the SINGLE write choke point — every service
     * and factory creates with the plaintext `otp_code`, so nothing has to remember to hash.
     * Hydration from the DB uses setRawAttributes and does NOT pass here, so a stored
     * digest is never re-hashed (no double-hash).
     */
    public function setOtpCodeAttribute($value): void
    {
        $this->attributes['otp_code'] = self::hashCode((string) $value);
    }

    /**
     * Does the supplied code match this OTP?
     *
     * Compared in constant time so verification does not leak the code through
     * response timing. This is also why the code is no longer matched inside the
     * lookup query: a wrong guess has to reach the row so the attempt is counted.
     * RV-16: the stored column is an HMAC, so the supplied plaintext is hashed with the
     * same key and the two digests compared — the raw code is never read back out.
     */
    public function matchesCode(string $code): bool
    {
        return hash_equals((string) $this->attributes['otp_code'], self::hashCode($code));
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
            'verified_at' => Carbon::now(),
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
