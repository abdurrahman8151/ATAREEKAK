<?php

namespace App\Http\Middleware;

use App\Services\JwtService;
use App\Models\User;
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
        if (!$token) {
            return $this->fail('TOKEN_MISSING', 'Unauthenticated');
        }

        // 2. Decode: verify signature + expiry
        $payload = $this->jwtService->decodeToken($token);
        if (!$payload) {
            return $this->fail('TOKEN_INVALID', 'Invalid or expired token');
        }

        // 3. Must be an access token
        if (($payload['type'] ?? null) !== 'access') {
            return $this->fail('TOKEN_TYPE_INVALID', 'Invalid token type');
        }

        // 4. Load user — Redis cache, falls back to DB on miss
        //    OPTIMIZATION: Replaces User::find() which hit DB on every request.
        //    Saves ~50ms per request across every authenticated endpoint in the app.
        //    Cache is busted by JwtService::revokeAllTokens() on logout/ban/password reset.
        $user = $this->jwtService->findUserCached($payload['sub']);
        if (!$user) {
            return $this->fail('USER_NOT_FOUND', 'User not found');
        }

        // 5. Ban check — status -1 = banned
        if ($user->status == -1) {
            // Auto-lift expired temporary bans
            if (
                $user->ban_type === 'temporary'
                && $user->ban_expires_at !== null
                && now()->gt($user->ban_expires_at)
            ) {
                // Ban expired — restore to logged-out, user must log in again
                $user->update([
                    'status'         => 0,
                    'ban_reason'     => null,
                    'ban_type'       => null,
                    'banned_at'      => null,
                    'ban_expires_at' => null,
                    'banned_by'      => null,
                ]);

                // Bust the cache — the cached object still has status=-1.
                // Without this, the user stays "banned" for up to 5 minutes
                // even after the ban is lifted.
                Cache::forget("auth.user.{$user->id}");

                // Fall through to the inactive check below (status is now 0)
            } else {
                // Still banned — only /api/contact is allowed
                if (!$request->is('api/contact')) {
                    return response()->json([
                        'status'  => 'error',
                        'code'    => 'USER_BANNED',
                        'message' => 'Your account has been banned. You may only use the contact form.',
                        'ban'     => [
                            'reason'     => $user->ban_reason,
                            'type'       => $user->ban_type,
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
        if (!$this->jwtService->validateTokenVersion($payload, $user)) {
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
            'status'  => 'error',
            'code'    => $code,
            'message' => $message,
        ], 401);
    }
}
