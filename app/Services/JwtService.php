<?php

namespace App\Services;

use App\DTOs\Auth\CachedUser;
use App\Models\RefreshToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class JwtService
{
    // =========================================================================
    // PUBLIC — TOKEN PAIR
    // =========================================================================

    /**
     * Generate access + refresh token pair for a regular user.
     */
    public function generateTokenPair(User $user): array
    {
        $accessToken = $this->generateAccessToken($user);
        $refreshToken = $this->generateRefreshToken($user);

        return [
            'access_token' => $accessToken['token'],
            'access_token_expires_at' => $accessToken['expires_at'],
            'refresh_token' => $refreshToken['token'],
            'refresh_token_expires_at' => $refreshToken['expires_at'],
            'token_type' => 'Bearer',
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
        if (! hash_equals($expectedSignature, $signature)) {
            return null;
        }

        // Decode payload
        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);
        if (! $payload) {
            return null;
        }

        // Check expiry
        if (isset($payload['exp']) && Carbon::now()->timestamp > $payload['exp']) {
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
        if (! isset($payload['ver'])) {
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

        // RV-29: the lookup no longer filters on revoked — a REVOKED, still-unexpired
        // token presented again is not just "invalid", it is the signature of theft
        // (the legitimate holder already rotated past it). Returning null quietly —
        // the old behaviour — meant a stolen token being replayed by the thief went
        // completely unnoticed while the thief's copies stayed usable elsewhere.
        $storedToken = RefreshToken::where('token', $hashedToken)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (! $storedToken) {
            return null;
        }

        if ($storedToken->revoked) {
            // Reuse detected iff the same account still holds an ACTIVE token: that
            // means somebody rotated normally and a stale copy surfaced afterwards —
            // two live copies of one secret. (If the whole lineage is already dead,
            // e.g. logout-all, this is just an invalid token, same as before.)
            $othersActive = RefreshToken::where('user_id', $storedToken->user_id)
                ->where('revoked', false)
                ->where('expires_at', '>', Carbon::now())
                ->exists();

            if ($othersActive) {
                Log::warning('Refresh token REUSE detected — revoking all tokens for user', [
                    'user_id' => $storedToken->user_id,
                    'token_id' => $storedToken->id,
                ]);

                // OWASP-canonical response: invalidate the ENTIRE lineage — refresh
                // rows AND outstanding access tokens (revokeAllTokens bumps
                // token_version) — so whichever party holds a stale copy loses access.
                $this->revokeAllTokens($storedToken->user_id);
            }

            return null;
        }

        $user = $this->findUserCached($storedToken->user_id);
        if (! $user) {
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
     *
     * ── Decision un11 (owner, 2026-10-02): WHAT IS CACHED ────────────────────
     * A `CachedUser` DTO, NOT the User model. `Cache::remember` serialises whatever it is given,
     * so caching the model wrote every user's bcrypt `password` hash into Redis in plain
     * serialized form on every miss. No code reads `$request->user()->password` (every Hash::check
     * in the app uses a freshly-queried model or the separate Employee model), so the credential is
     * pure exposure. The DTO carries everything the app actually reads and drops `password`; the
     * model is rebuilt from it on read. See app/DTOs/Auth/CachedUser.php for the full rationale.
     *
     * The return type is still `?User` so every existing caller (middleware, controllers, tests) is
     * unaffected — the change is entirely inside this method.
     */
    public function findUserCached(int $userId): ?User
    {
        /** @var CachedUser|null $dto */
        $dto = Cache::remember(
            "auth.user.{$userId}",
            300, // 5 minutes
            // RV-28 (primary-only read): on a cache MISS we rehydrate the user from the
            // database, and in production that read would go to the replica. If the cache was
            // just busted (ban, password reset, role change) and the replica has not caught up,
            // this miss path would cache a STALE user for the full 5 minutes — and every
            // subsequent request would use it. Force the primary on the miss path so a busted
            // cache always rehydrates from authoritative data.
            fn () => CachedUser::fromOptionalModel(
                User::with('profile')->useWritePdo()->find($userId)
            )
        );

        return $dto?->toUser();
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
            'iss' => config('app.url'),
            'sub' => $user->id,
            'iat' => Carbon::now()->timestamp,
            'exp' => $expiresAt->timestamp,
            'jti' => Str::uuid()->toString(),
            'type' => 'access',
            'ver' => $user->token_version,
        ];

        return [
            'token' => $this->encodeToken($payload),
            'expires_at' => $expiresAt->toDateTimeString(),
            'expires_in' => $expiresIn * 60,
        ];
    }

    /**
     * Generate, hash, and store a refresh token in the DB.
     * Returns the plaintext token (returned to client once, never stored plain).
     */
    private function generateRefreshToken(User $user): array
    {
        $expiresIn = config('jwt.refresh_ttl', 10080); // minutes, default 7 days
        $expiresAt = Carbon::now()->addMinutes($expiresIn);
        $tokenString = Str::random(64);

        RefreshToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', $tokenString),
            'expires_at' => $expiresAt,
            'user_agent' => request()->userAgent(),
            'ip_address' => request()->ip(),
        ]);

        return [
            'token' => $tokenString,
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

        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
        $signature = $this->generateSignature($headerEncoded, $payloadEncoded);

        return "{$headerEncoded}.{$payloadEncoded}.{$signature}";
    }

    private function generateSignature(string $header, string $payload): string
    {
        $secret = config('jwt.secret');
        $algo = strtolower(config('jwt.algo', 'HS256'));

        $algoMap = [
            'hs256' => 'sha256',
            'hs384' => 'sha384',
            'hs512' => 'sha512',
        ];

        $hashAlgo = $algoMap[$algo] ?? 'sha256';
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
