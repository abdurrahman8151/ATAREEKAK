<?php

namespace App\Enums;

/**
 * Decision un13 (owner, 2026-10-02, Option A): a clean account `status` — "root cause of ban bugs".
 *
 * `users.status` is a `tinyint` that has been doing three jobs at once, with bare literals at every
 * call site: -1 banned, 0 logged-out, 1 active. The cost is not the magic numbers themselves, it is
 * that "banned" is a *combination* of a status value AND a time condition (`ban_type` +
 * `ban_expires_at`), and that combination was re-implemented in three places that disagreed:
 *
 *   - `AdminBanController::ban` wrote status -1 + ban fields inline;
 *   - `AdminBanController::unban` wrote status 0 + cleared the ban fields inline;
 *   - `JwtAuthMiddleware` auto-lifted an expired temporary ban with its OWN copy of the same write.
 *
 * When those drift, a temporary ban either never lifts or lifts twice. The audit already recorded
 * one such bug: a temporary ban whose tokens were revoked could never be auto-lifted (auto-lift only
 * runs on an AUTHENTICATED request, and a banned user's tokens were dead), so the account stayed
 * locked forever — the only remaining door, login, ignored the expiry.
 *
 * THIS ENUM removes the first half of the problem (one definition of the values). `BanService`
 * removes the second (one definition of the WRITE). Both together are the decision; the enum alone
 * would only have tidied literals.
 *
 * The integer values are deliberately unchanged — they are already persisted in the database and in
 * API responses, and re-numbering them would be a destructive migration for no benefit.
 */
enum AccountStatus: int
{
    /** Signed out, or just registered. Not verified yet. May log in. */
    case LOGGED_OUT = 0;

    /** Signed in and allowed to act. */
    case ACTIVE = 1;

    /**
     * Banned — *subject to `User::banHasExpired()`*.
     *
     * Being in this state does not by itself mean the ban is in force: a temporary ban whose
     * `ban_expires_at` has passed has lapsed. Always ask `User::isBannedNow()`, never compare
     * against this case directly. That distinction is the bug this decision exists to fix.
     */
    case BANNED = -1;

    /** Can this status act on the platform (i.e. is it not banned and not signed out)? */
    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Human-readable label for admin surfaces.
     *
     * NOTE the wording for LOGGED_OUT: it is the state a newly registered or just-unbanned account
     * is in. Calling it "inactive" would collide with suspension semantics the product does not have.
     */
    public function label(): string
    {
        return match ($this) {
            self::LOGGED_OUT => 'Logged out',
            self::ACTIVE => 'Active',
            self::BANNED => 'Banned',
        };
    }
}
