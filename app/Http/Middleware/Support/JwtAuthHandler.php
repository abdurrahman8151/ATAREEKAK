<?php

namespace App\Http\Middleware\Support;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Modules\Identity\Services\JwtService;
use App\Services\Admin\BanService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * JWT authentication decision for JwtAuthMiddleware, moved out verbatim.
 *
 * JwtAuthMiddleware keeps its class name (the 'jwt' alias in Kernel points at it), its constructor and
 * handle signature, and delegates here. The body is the original one: the TOKEN_MISSING, TOKEN_INVALID,
 * TOKEN_TYPE_INVALID, USER_NOT_FOUND, USER_BANNED, USER_INACTIVE and TOKEN_INVALIDATED refusals, the RV-04
 * staff-token guard, the expired-ban lift, and the /api/contact exception are all kept exactly.
 */
class JwtAuthHandler
{
    public function __construct(protected JwtService $jwtService, protected BanService $banService) {}

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

        $payloadRefusal = $this->refuseNonUserPayload($payload);
        if ($payloadRefusal !== null) {
            return $payloadRefusal;
        }

        // 4. Load user — Redis cache, falls back to DB on miss
        //    OPTIMIZATION: Replaces User::find() which hit DB on every request.
        //    Saves ~50ms per request across every authenticated endpoint in the app.
        //    Cache is busted by JwtService::revokeAllTokens() on logout/ban/password reset.
        $user = $this->jwtService->findUserCached($payload['sub']);
        if (! $user) {
            return $this->fail('USER_NOT_FOUND', 'User not found');
        }

        $banResponse = $this->handleBanState($request, $next, $user);
        if ($banResponse !== null) {
            return $banResponse;
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

        return $this->authenticate($request, $next, $user);
    }

    /**
     * Steps 3 and 3b: the payload must be a USER access token, never a staff one.
     *
     * 3. Must be an access token
     *
     * 3b. RV-04: must be a USER token.
     *     Staff and user tokens are signed with the same secret and both carry
     *     `type=access`; staff tokens identify themselves with `sub_type=employee`
     *     (and `StaffJwtService::decodeToken()` already refuses tokens without it,
     *     so user->staff was never possible). Without this check the reverse
     *     replay worked: a staff token whose `sub` (employee id) collided with a
     *     user id and whose `ver` matched that user's `token_version` was accepted
     *     as that user. Rejecting the presence of `sub_type` closes it and cannot
     *     affect legitimate user tokens, which never carry the claim.
     */
    private function refuseNonUserPayload(array $payload): ?Response
    {
        if (($payload['type'] ?? null) !== 'access') {
            return $this->fail('TOKEN_TYPE_INVALID', 'Invalid token type');
        }

        if (isset($payload['sub_type'])) {
            return $this->fail('TOKEN_TYPE_INVALID', 'Invalid token type');
        }

        return null;
    }

    /**
     * Step 5: the ban gate.
     *
     * Lifts an expired ban and lets the request fall through, or refuses a live ban —
     * except on /api/contact, where the banned user is still attached to the request.
     * Returns null when the normal authenticated path may continue.
     */
    private function handleBanState(Request $request, Closure $next, User $user): ?Response
    {
        // 5. Ban check. Decision un13: this used to contain its OWN copy of the un-ban write (status 0 +
        // clear every ban field + forget one cache key), which is how the three copies of "unban"
        // drifted apart. It now calls the single implementation.
        //
        // The service re-reads the authoritative row from the primary under a lock, so the write can
        // never be applied to a cache-shaped partial model (the hazard recorded in R2 sec 30) and
        // cannot race an admin who has just changed the ban. `useWritePdo` matters here too: after a
        // ban write the replica may lag, and reading a stale replica row would write the ban fields
        // straight back over the lift.
        if ($user->status != AccountStatus::BANNED->value) {
            return null;
        }

        if ($user->banHasExpired()) {
            $this->banService->liftExpiredBan($user->id);

            // Fall through to the inactive check below (status is now LOGGED_OUT)
            return null;
        }

        // Still banned — only /api/contact is allowed
        if (! $request->is('api/contact')) {
            return $this->bannedResponse($user);
        }

        return $this->authenticate($request, $next, $user);
    }

    /**
     * The 403 body a still-banned account gets on every route but /api/contact.
     */
    private function bannedResponse(User $user): Response
    {
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

    /**
     * Attach the resolved user to the request and continue down the pipeline.
     */
    private function authenticate(Request $request, Closure $next, User $user): Response
    {
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
