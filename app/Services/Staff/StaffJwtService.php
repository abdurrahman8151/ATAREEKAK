<?php

namespace App\Services\Staff;

use App\Models\Employee;
use App\Models\StaffRefreshToken;
use Carbon\Carbon;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class StaffJwtService
{
    private const ALGORITHM = 'HS256';

    private const REFRESH_TTL_DAYS = 30;

    private const SUB_TYPE = 'employee';

    // ── Token generation ──────────────────────────────────────────────────────

    public function generateTokenPair(Employee $employee): array
    {
        $accessToken = $this->generateAccessToken($employee);
        $refreshEntry = $this->generateRefreshToken($employee);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshEntry['token'],
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTtlSeconds(),
        ];
    }

    /**
     * T4-4: this used to be a hardcoded constant (ACCESS_TTL = 3600) that
     * ignored config entirely, so staff token lifetime was unrelated to the
     * user one and to the documented value. It now reads jwt.staff_ttl
     * (minutes) exactly like JwtService reads jwt.ttl. The default (60 minutes)
     * reproduces the old 3600 seconds, so this change is behaviour-preserving.
     */
    private function accessTtlSeconds(): int
    {
        return max(1, (int) config('jwt.staff_ttl', 60)) * 60;
    }

    // ── Token validation ──────────────────────────────────────────────────────

    /**
     * Decode and verify an access token.
     * Returns the payload array on success, null on any failure.
     */
    public function decodeToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret(), self::ALGORITHM));
            $payload = (array) $decoded;

            // Reject tokens not issued for employees.
            if (($payload['sub_type'] ?? null) !== self::SUB_TYPE) {
                return null;
            }

            return $payload;
        } catch (ExpiredException $e) {
            Log::debug('Staff access token expired', ['error' => $e->getMessage()]);

            return null;
        } catch (SignatureInvalidException $e) {
            Log::warning('Staff JWT signature invalid', ['error' => $e->getMessage()]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('Staff JWT decode error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Guard against tokens issued before the last password change / logout-all.
     */
    public function validateTokenVersion(array $payload, Employee $employee): bool
    {
        return ((int) ($payload['ver'] ?? -1)) === $employee->token_version;
    }

    // ── Refresh ───────────────────────────────────────────────────────────────

    /**
     * Consume a refresh token and issue a new access + refresh pair (rotation).
     */
    public function refreshAccessToken(string $refreshToken): ?array
    {
        // T2-11: look up by DIGEST. The plaintext value handed to the client is
        // never the value stored, mirroring JwtService::refreshAccessToken()
        // (app/Services/JwtService.php:123).
        $tokenRecord = StaffRefreshToken::where('token', $this->hashToken($refreshToken))
            ->with('employee')
            ->first();

        if (! $tokenRecord) {
            return null;
        }

        // RV-29: REUSE DETECTION (mirror of JwtService::refreshAccessToken). A
        // revoked-but-still-unexpired token presented again means two live copies of
        // one secret — the legitimate holder rotated past it, so whoever else holds it
        // is not the holder. Revoke the ENTIRE lineage (refresh rows + access tokens,
        // via revokeAllTokens bumping token_version) whichever party is the thief.
        // Already-expired rows keep the old silent-invalid answer: nothing to protect.
        if ($tokenRecord->revoked && ! $tokenRecord->isExpired()) {
            $othersActive = StaffRefreshToken::where('employee_id', $tokenRecord->employee_id)
                ->where('revoked', false)
                ->where('expires_at', '>', Carbon::now())
                ->exists();

            if ($othersActive) {
                Log::warning('Staff refresh token REUSE detected — revoking all tokens for employee', [
                    'employee_id' => $tokenRecord->employee_id,
                    'token_id' => $tokenRecord->id,
                ]);

                $this->revokeAllTokens($tokenRecord->employee_id);
            }

            return null;
        }

        if (! $tokenRecord->isValid()) {
            return null;
        }

        $employee = $tokenRecord->employee;

        if (! $employee || ! $employee->is_active) {
            return null;
        }

        // Rotate: revoke old, issue new pair.
        $tokenRecord->update(['revoked' => true]);

        return $this->generateTokenPair($employee);
    }

    // ── Revocation ────────────────────────────────────────────────────────────

    /**
     * Revoke all refresh tokens and bump token_version to invalidate
     * all outstanding access tokens (logout-all / password change).
     */
    public function revokeAllTokens(int $employeeId): void
    {
        StaffRefreshToken::where('employee_id', $employeeId)
            ->where('revoked', false)
            ->update(['revoked' => true]);

        Employee::where('id', $employeeId)
            ->increment('token_version');
    }

    // ── Maintenance ───────────────────────────────────────────────────────────

    public function cleanupExpiredTokens(): int
    {
        // T4-3: this relied on accidental operator precedence —
        //   where('expires_at', '<', now())->orWhere('revoked', true)->delete()
        // produced (expires_at < now OR revoked = 1) only because there were no
        // other clauses; a single added constraint would silently widen the
        // DELETE across the whole table (revoked rows anywhere). Parenthesised
        // now so the intent is structural, not incidental. The emitted SQL is
        // unchanged for today's callers.
        return StaffRefreshToken::query()
            ->where(static function ($q): void {
                $q->where('expires_at', '<', now())
                    ->orWhere('revoked', true);
            })
            ->delete();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function generateAccessToken(Employee $employee): string
    {
        $now = time();

        $payload = [
            'iss' => config('app.name'),
            'sub' => $employee->id,
            'sub_type' => self::SUB_TYPE,
            'role' => $employee->role->value,
            'type' => 'access',
            'ver' => $employee->token_version,
            'iat' => $now,
            'exp' => $now + $this->accessTtlSeconds(),
        ];

        return JWT::encode($payload, $this->secret(), self::ALGORITHM);
    }

    private function generateRefreshToken(Employee $employee): array
    {
        $token = Str::random(64);

        // T2-11: store only the SHA-256 digest, exactly as the user path does
        // (JwtService::generateRefreshToken() at app/Services/JwtService.php:281).
        // A refresh token is a 30-day bearer credential that mints fresh access
        // tokens, so a database disclosure must not hand over usable sessions.
        // The raw value is returned to the client and is never persisted.
        $record = StaffRefreshToken::create([
            'employee_id' => $employee->id,
            'token' => $this->hashToken($token),
            'expires_at' => now()->addDays(self::REFRESH_TTL_DAYS),
            'revoked' => false,
            'user_agent' => request()->userAgent(),
            'ip_address' => request()->ip(),
        ]);

        return ['token' => $token, 'record' => $record];
    }

    /**
     * Digest used as the persistence/lookup key for staff refresh tokens.
     * Deliberately identical in form to the user system — hash('sha256', $raw),
     * lowercase hex, 64 chars — so the two cannot drift apart again and so the
     * value fits the existing varchar(64) UNIQUE column unchanged.
     */
    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function secret(): string
    {
        $secret = config('jwt.secret');

        if (empty($secret)) {
            throw new \RuntimeException(
                'JWT secret is not configured. Run: php artisan jwt:secret'
            );
        }

        // FIX: return the raw secret, matching JwtService (user auth).
        // The previous base64_decode() produced a different key than JwtService
        // uses, so staff tokens could never be cross-verified. In test
        // environments JWT_SECRET is often not valid base64, causing
        // base64_decode() to return false → TypeError → 500 on every staff login.
        return $secret;
    }
}
