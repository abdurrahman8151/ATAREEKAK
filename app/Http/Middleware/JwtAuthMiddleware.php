<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Services\Admin\BanService;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Status codes:
 *   -1 = banned
 *    0 = logged out
 *    1 = active
 */
class JwtAuthMiddleware
{
    public function __construct(protected JwtService $jwtService) {}

    public function handle(Request $request, Closure $next): Response
    {
        // 1. Extract Bearer token
        $token = $this->extractToken($request);
        if (! $token) {
            return $this->fail('TOKEN_MISSING', 'Unauthenticated');
        }

        // 2. Decode: verify signature + expiry
        $payload = $this->jwtService->decodeToken($token);
        if (! $payload) {
            return $this->fail('TOKEN_INVALID', 'Invalid or expired token');
        }

        // 3. Must be an access token
        if (($payload['type'] ?? null) !== 'access') {
            return $this->fail('TOKEN_TYPE_INVALID', 'Invalid token type');
        }

        // 3b. RV-04: must be a USER token.
        //     Staff and user tokens are signed with the same secret and both carry
        //     `type=access`; staff tokens identify themselves with `sub_type=employee`
        //     (and `StaffJwtService::decodeToken()` already refuses tokens without it,
        //     so user->staff was never possible). Without this check the reverse
        //     replay worked: a staff token whose `sub` (employee id) collided with a
        //     user id and whose `ver` matched that user's `token_version` was accepted
        //     as that user. Rejecting the presence of `sub_type` closes it and cannot
        //     affect legitimate user tokens, which never carry the claim.
        if (isset($payload['sub_type'])) {
            return $this->fail('TOKEN_TYPE_INVALID', 'Invalid token type');
        }

        // 4. Load user — Redis cache, falls back to DB on miss
        //    OPTIMIZATION: Replaces User::find() which hit DB on every request.
        //    Saves ~50ms per request across every authenticated endpoint in the app.
        //    Cache is busted by JwtService::revokeAllTokens() on logout/ban/password reset.
        $user = $this->jwtService->findUserCached($payload['sub']);
        if (! $user) {
            return $this->fail('USER_NOT_FOUND', 'User not found');
        }

        // 5. Ban check. Decision un13: this used to contain its OWN copy of the un-ban write (status 0 +
        // clear every ban field + forget one cache key), which is how the three copies of "unban"
        // drifted apart. It now calls the single implementation.
        //
        // The service re-reads the authoritative row from the primary under a lock, so the write can
        // never be applied to a cache-shaped partial model (the hazard recorded in R2 sec 30) and
        // cannot race an admin who has just changed the ban. `useWritePdo` matters here too: after a
        // ban write the replica may lag, and reading a stale replica row would write the ban fields
        // straight back over the lift.
        if ($user->status == AccountStatus::BANNED->value) {
            if ($user->banHasExpired()) {
                app(BanService::class)->liftExpiredBan($user->id);

                // Fall through to the inactive check below (status is now LOGGED_OUT)
            } else {
                // Still banned — only /api/contact is allowed
                if (! $request->is('api/contact')) {
                    return response()->json([
                        'status' => 'error',
                        'code' => 'USER_BANNED',
                        'message' => 'Your account has been banned. You may only use the contact form.',
                        'ban' => [
                            'reason' => $user->ban_reason,
                            'type' => $user->ban_type,
                            'expires_at' => $user->ban_expires_at?->toIso8601String(),
                        ],
                    ], 403);
                }

                $request->setUserResolver(fn () => $user);
                Auth::setUser($user);

                return $next($request);
            }
        }

        // 6. Logged-out check — status 0
        if ($user->status == 0) {
            return $this->fail('USER_INACTIVE', 'User account is inactive');
        }

        // 7. Token version check — rejects tokens issued before last
        //    password change or logout-all.
        //    Uses the already-loaded cached user — zero extra DB queries.
        if (! $this->jwtService->validateTokenVersion($payload, $user)) {
            return $this->fail(
                'TOKEN_INVALIDATED',
                'Your session has been invalidated. Please log in again.'
            );
        }

        $request->setUserResolver(fn () => $user);
        Auth::setUser($user);

        return $next($request);
    }

    private function extractToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : null;
    }

    private function fail(string $code, string $message): Response
    {
        return response()->json([
            'status' => 'error',
            'code' => $code,
            'message' => $message,
        ], 401);
    }
}
