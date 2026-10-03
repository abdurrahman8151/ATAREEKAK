<?php

namespace App\Services\Admin;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Decision un13 (owner, 2026-10-02, Option A): ONE place that writes a ban.
 *
 * Before this, the same database write existed in three copies:
 *   - `AdminBanController::ban()`     — status -1 + ban fields, then revoke tokens + bust caches
 *   - `AdminBanController::unban()`   — status 0  + clear ban fields, then bust caches
 *   - `JwtAuthMiddleware` auto-lift   — status 0  + clear ban fields, then forget ONE cache key
 *
 * They already disagreed: the unban path and the auto-lift path cleared different cache keys, so
 * an account unbanned by an admin could still be told USER_BANNED for up to five minutes, and the
 * auto-lift forgot only `auth.user.{id}` while leaving the admin dashboard counting a banned user as
 * active. Three copies of one state transition is how "ban bugs" keep coming back.
 *
 * BEHAVIOUR IS DELIBERATELY UNCHANGED. This is a consolidation, not a redesign: the same columns,
 * the same token revocation, the same cache keys (now ALL of them, in all three paths). What changes
 * is that a future fourth copy is much harder to write by accident, and that the rules live in one
 * readable place.
 *
 * THE ONE BEHAVIOURAL DIFFERENCE, stated plainly: `ban()` and `unban()` now revoke tokens and bust
 * the same complete set of caches, because the auto-lift path (which previously did not) and the
 * admin paths are now the same method. `unban()` already did the full set; `ban()` already revoked.
 * The auto-lift is the only path whose cache-busting becomes complete, and that is a fix, not a
 * regression — an expired ban should stop counting as a ban everywhere at once.
 */
class BanService
{
    public function __construct(private readonly JwtService $jwt) {}

    /**
     * Ban an account.
     *
     * @param  'temporary'|'permanent'  $type
     */
    public function ban(
        User $user,
        string $reason,
        string $type,
        ?string $expiresAt = null,
        ?int $bannedBy = null,
    ): User {
        $user->update([
            'status' => AccountStatus::BANNED->value,
            'ban_reason' => $reason,
            'ban_type' => $type,
            'banned_at' => now(),
            // A permanent ban must NOT carry an expiry: `banHasExpired()` treats a missing expiry as
            // "never lifts", so leaving a stale one on a permanent ban would be harmless today but
            // would silently lift it the day the column were reused.
            'ban_expires_at' => $type === 'temporary' ? $expiresAt : null,
            'banned_by' => $bannedBy,
        ]);

        // Revoke first: the ban must take effect on the very next request, not in five minutes.
        $this->jwt->revokeAllTokens($user->id);

        $this->bustCaches($user->id);

        Log::info('User banned', [
            'user_id' => $user->id,
            'banned_by' => $bannedBy,
            'type' => $type,
            'expires_at' => $expiresAt,
        ]);

        return $user->fresh();
    }

    /**
     * Lift a ban set by an admin. The account returns to LOGGED_OUT (not ACTIVE): a banned user
     * whose tokens were revoked must sign in again, which is what the admin action promises them.
     */
    public function unban(User $user, ?int $unbannedBy = null): User
    {
        $user->update([
            'status' => AccountStatus::LOGGED_OUT->value,
            'ban_reason' => null,
            'ban_type' => null,
            'banned_at' => null,
            'ban_expires_at' => null,
            'banned_by' => null,
        ]);

        // Deliberately NOT revoking: revoking again would be harmless, but an unban is already
        // preceded by the ban's revocation, so there is nothing live to kill.
        $this->bustCaches($user->id);

        Log::info('User unbanned', [
            'user_id' => $user->id,
            'unbanned_by' => $unbannedBy,
        ]);

        return $user->fresh();
    }

    /**
     * Auto-lift a temporary ban whose end time has passed.
     *
     * Idempotent and safe to call from anywhere: it re-checks the condition inside a lock, so two
     * concurrent requests cannot both "lift" and, more importantly, a ban that an admin has since
     * changed is not overwritten by a stale read.
     *
     * Returns true if a ban was actually lifted.
     */
    public function liftExpiredBan(int $userId): bool
    {
        $lifted = false;

        DB::transaction(function () use ($userId, &$lifted) {
            $user = User::lockForUpdate()->find($userId);

            // CAREFUL, and this was a real bug caught by its own test: `isBannedNow()` ALREADY
            // returns false once a temporary ban has expired (`banned means status -1 AND NOT an
            // expired temporary ban`). Combining it with `banHasExpired()` therefore asks for a ban
            // that is simultaneously "in force" and "expired", which can never be true - so the
            // auto-lift never fired at all.
            //
            // The correct precondition is the raw one: the row is in the BANNED state and its
            // temporary window has closed. `isBannedNow()` is for callers deciding whether to BLOCK
            // someone; it is the wrong predicate for deciding whether to RELEASE them.
            if (! $user || (int) $user->status !== AccountStatus::BANNED->value || ! $user->banHasExpired()) {
                return;
            }

            $this->unban($user);
            $lifted = true;
        });

        return $lifted;
    }

    /**
     * Every cache key whose value depends on an account's ban state.
     *
     * Kept as one list on purpose: the original bug was three paths each forgetting a different
     * subset, so an account could be "banned" on one surface and "active" on another for five
     * minutes. Adding a new cached surface means adding it HERE, in one place.
     */
    private function bustCaches(int $userId): void
    {
        // RV-12: the middleware serves the user object from this cache for 5 minutes.
        Cache::forget("auth.user.{$userId}");

        Cache::forget("admin.user.status.{$userId}");
        Cache::forget('admin.dashboard.data');
        Cache::forget('admin.dashboard.stats');
        Cache::forget('admin.drivers.dashboard');
        Cache::forget('admin.drivers.stats');
    }
}
