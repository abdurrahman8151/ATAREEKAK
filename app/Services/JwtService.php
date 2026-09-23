<?php

namespace App\Services;

use App\Models\User;
use App\Models\RefreshToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Carbon\Carbon;

class JwtService
{
    private const SUB_TYPE = 'user';

    // =========================================================================
    // PUBLIC — TOKEN PAIR
    // =========================================================================

    /**
     * Generate access + refresh token pair for a regular user.
     */
    public function generateTokenPair(User $user): array
    {
        $accessToken  = $this->generateAccessToken($user);
        $refreshToken = $this->generateRefreshToken($user);

        return [
            'access_token'             => $accessToken['token'],
            'access_token_expires_at'  => $accessToken['expires_at'],
            'refresh_token'            => $refreshToken['token'],
            'refresh_token_expires_at' => $refreshToken['expires_at'],
            'token_type'               => 'Bearer',
        ];
    }

    /**
     * Generate access + refresh token pair for an admin user.
     * Embeds is_admin and admin_type claims so AdminJwtMiddleware can verify.
     */
    public function generateAdminTokenPair(User $adminUser, string $adminType): array
    {
        $accessToken  = $this->generateAdminAccessToken($adminUser, $adminType);
        $refreshToken = $this->generateRefreshToken($adminUser);

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken['token'],
            'expires_in'    => config('jwt.ttl', 15) * 60,
            'token_type'    => 'Bearer',
            'admin_type'    => $adminType,
        ];
    }

    // =========================================================================
    // PUBLIC — DECODE & VALIDATE
    // =========================================================================

    /**
     * Decode and verify a JWT token (signature + expiry).
     * Does NOT check token_version — that is done in the middleware
     * after the user is loaded, to avoid an extra DB query.
     */
    public function decodeToken(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signature] = $parts;

        // Verify signature
        $expectedSignature = $this->generateSignature($headerEncoded, $payloadEncoded);
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        // Decode payload
        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);
        if (!$payload) {
            return null;
        }

        // Check expiry
        if (isset($payload['exp']) && Carbon::now()->timestamp > $payload['exp']) {
            return null;
        }

        // Reject tokens issued for a different identity realm (e.g. a staff
        // Employee token). StaffJwtService signs with the same raw secret,
        // so a staff-issued token verifies here too unless sub_type is
        // checked — without this, its numeric `sub` would be looked up
        // against `users` and resolved as whichever unrelated user happens
        // to share that id (BUG-13). Tokens issued before this claim
        // existed carry no sub_type at all and are still accepted.
        if (isset($payload['sub_type']) && $payload['sub_type'] !== self::SUB_TYPE) {
            return null;
        }

        return $payload;
    }

    /**
     * Validate that the token's 'ver' claim matches the user's current token_version.
     *
     * Call this in JwtAuthMiddleware AFTER decodeToken() + user loaded from cache/DB.
     * Zero extra DB queries — user is already loaded.
     *
     * Returns false if:
     *   - 'ver' claim is missing (token issued before this system was added → force re-login)
     *   - 'ver' doesn't match user.token_version (password was changed → token is dead)
     */
    public function validateTokenVersion(array $payload, User $user): bool
    {
        if (!isset($payload['ver'])) {
            return false;
        }

        return (int) $payload['ver'] === (int) $user->token_version;
    }

    // =========================================================================
    // PUBLIC — REFRESH (with rotation)
    // =========================================================================

    /**
     * Validate refresh token, REVOKE IT, and issue a brand new pair.
     *
     * Rotation: each refresh token is single-use only.
     * If an attacker uses a stolen refresh token, the legitimate user's
     * next refresh will fail — a clear signal of compromise.
     */
    public function refreshAccessToken(string $refreshToken): ?array
    {
        $hashedToken = hash('sha256', $refreshToken);

        $storedToken = RefreshToken::where('token', $hashedToken)
            ->where('expires_at', '>', Carbon::now())
            ->where('revoked', false)
            ->first();

        if (!$storedToken) {
            return null;
        }

        $user = $this->findUserCached($storedToken->user_id);
        if (!$user) {
            return null;
        }

        // ROTATE: revoke old token before issuing new pair
        $storedToken->update(['revoked' => true]);

        return $this->generateTokenPair($user);
    }

    // =========================================================================
    // PUBLIC — REVOKE (password change / logout-all)
    // =========================================================================

    /**
     * Immediately kills every token for this user across all devices:
     *
     *   1. Revokes all refresh tokens in DB → can't mint new access tokens.
     *   2. Increments token_version → all current access tokens fail the
     *      'ver' check in JwtAuthMiddleware on their very next request.
     *   3. Busts the user cache → next request gets fresh user from DB
     *      with the new token_version — prevents stale cache from
     *      allowing revoked tokens through.
     *
     * Call this on: password reset, "logout from all devices", admin-forced logout.
     */
    public function revokeAllTokens(int $userId): void
    {
        RefreshToken::where('user_id', $userId)
            ->where('revoked', false)
            ->update(['revoked' => true]);

        // Bump version — kills every outstanding access token immediately
        User::where('id', $userId)->increment('token_version');

        // CRITICAL: Bust the user cache so the new token_version takes effect
        // immediately. Without this, the cache would serve the old version for
        // up to 5 minutes, allowing revoked tokens to still pass validation.
        Cache::forget("auth.user.{$userId}");
    }

    // =========================================================================
    // PUBLIC — USER CACHE HELPER (used by middleware)
    // =========================================================================

    /**
     * Find user by ID with Redis caching.
     *
     * OPTIMIZATION: Saves ~50ms per request by avoiding a DB query
     * on every authenticated endpoint. The JWT 'ver' claim handles
     * revocation — if token_version changes, the 'ver' check fails
     * even if the cached user object is slightly stale.
     *
     * Cache is busted immediately by revokeAllTokens() so bans and
     * password resets take effect on the next request.
     *
     * TTL: 5 minutes. Matches the access token TTL so a logged-out
     * user's cache entry expires around the same time their token does.
     */
    public function findUserCached(int $userId): ?User
    {
        return Cache::remember(
            "auth.user.{$userId}",
            300, // 5 minutes
            fn() => User::with('profile')->find($userId)
        );
    }

    // =========================================================================
    // PUBLIC — CLEANUP (scheduled command)
    // =========================================================================

    public function cleanupExpiredTokens(): int
    {
        return RefreshToken::where('expires_at', '<', Carbon::now())
            ->orWhere('revoked', true)
            ->delete();
    }

    // =========================================================================
    // PRIVATE — TOKEN GENERATORS
    // =========================================================================

    /**
     * Build a signed access token for a regular user.
     * Includes 'ver' (token_version) so old tokens are rejected after
     * a password change without any extra DB query.
     */
    private function generateAccessToken(User $user): array
    {
        $expiresIn = config('jwt.ttl', 15); // minutes
        $expiresAt = Carbon::now()->addMinutes($expiresIn);

        $payload = [
            'iss'      => config('app.url'),
            'sub'      => $user->id,
            'sub_type' => self::SUB_TYPE,
            'iat'      => Carbon::now()->timestamp,
            'exp'      => $expiresAt->timestamp,
            'jti'      => Str::uuid()->toString(),
            'type'     => 'access',
            'ver'      => $user->token_version,   // ← token_version claim
        ];

        return [
            'token'      => $this->encodeToken($payload),
            'expires_at' => $expiresAt->toDateTimeString(),
            'expires_in' => $expiresIn * 60,
        ];
    }

    /**
     * Build a signed access token for an admin user.
     * Embeds is_admin + admin_type claims for AdminJwtMiddleware.
     */
    private function generateAdminAccessToken(User $adminUser, string $adminType): string
    {
        $expiresIn = config('jwt.ttl', 15);
        $now       = Carbon::now();

        $payload = [
            'iss'        => config('app.url'),
            'sub'        => $adminUser->id,
            'sub_type'   => self::SUB_TYPE,
            'iat'        => $now->timestamp,
            'exp'        => $now->addMinutes($expiresIn)->timestamp,
            'jti'        => Str::uuid()->toString(),
            'type'       => 'access',
            'ver'        => $adminUser->token_version,
            'is_admin'   => true,
            'admin_type' => $adminType,
        ];

        return $this->encodeToken($payload);
    }

    /**
     * Generate, hash, and store a refresh token in the DB.
     * Returns the plaintext token (returned to client once, never stored plain).
     */
    private function generateRefreshToken(User $user): array
    {
        $expiresIn   = config('jwt.refresh_ttl', 10080); // minutes, default 7 days
        $expiresAt   = Carbon::now()->addMinutes($expiresIn);
        $tokenString = Str::random(64);

        RefreshToken::create([
            'user_id'    => $user->id,
            'token'      => hash('sha256', $tokenString),
            'expires_at' => $expiresAt,
            'user_agent' => request()->userAgent(),
            'ip_address' => request()->ip(),
        ]);

        return [
            'token'      => $tokenString,
            'expires_at' => $expiresAt->toDateTimeString(),
            'expires_in' => $expiresIn * 60,
        ];
    }

    // =========================================================================
    // PRIVATE — JWT ENCODING HELPERS
    // =========================================================================

    private function encodeToken(array $payload): string
    {
        $header = ['typ' => 'JWT', 'alg' => config('jwt.algo', 'HS256')];

        $headerEncoded  = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
        $signature      = $this->generateSignature($headerEncoded, $payloadEncoded);

        return "{$headerEncoded}.{$payloadEncoded}.{$signature}";
    }

    private function generateSignature(string $header, string $payload): string
    {
        $secret  = config('jwt.secret');
        $algo    = strtolower(config('jwt.algo', 'HS256'));

        $algoMap = [
            'hs256' => 'sha256',
            'hs384' => 'sha384',
            'hs512' => 'sha512',
        ];

        $hashAlgo  = $algoMap[$algo] ?? 'sha256';
        $signature = hash_hmac($hashAlgo, "{$header}.{$payload}", $secret, true);

        return $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
