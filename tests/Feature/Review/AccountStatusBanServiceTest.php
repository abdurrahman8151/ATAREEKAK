<?php

namespace Tests\Feature\Review;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Services\Admin\BanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Decision un13 (owner, 2026-10-02, Option A): a clean account `status` + ONE `BanService`.
 *
 * The finding this fixes is not the magic numbers - it is that "un-ban" existed in THREE copies
 * (AdminBanController::unban, JwtAuthMiddleware auto-lift, and implicitly ban()) and they already
 * disagreed about WHICH CACHES to clear. An account unbanned by an admin could still be told
 * USER_BANNED for up to five minutes, while the admin dashboard still counted it as banned.
 *
 * These tests assert the CONSOLIDATION, not just the behaviour: whichever path lifts a ban, the
 * same set of caches must be gone. That is the property that keeps a fourth copy from drifting.
 */
class AccountStatusBanServiceTest extends TestCase
{
    use RefreshDatabase;

    private function bannedUser(string $type = 'permanent', ?string $expiresAt = null): User
    {
        $user = User::factory()->create(['status' => AccountStatus::BANNED->value]);

        app(BanService::class)->ban(
            $user,
            'Testing a ban for the consolidation test',
            $type,
            $expiresAt,
            null,
        );

        return $user->fresh();
    }

    /** @test */
    public function ban_writes_the_status_and_every_ban_field(): void
    {
        $user = $this->bannedUser('temporary', now()->addDay()->toDateTimeString());

        $this->assertSame(AccountStatus::BANNED->value, (int) $user->status);
        $this->assertSame('temporary', $user->ban_type);
        $this->assertNotNull($user->ban_expires_at);
        $this->assertNotNull($user->banned_at);
        $this->assertNotNull($user->ban_reason);
    }

    /** @test */
    public function a_permanent_ban_never_carries_an_expiry(): void
    {
        // `banHasExpired()` treats a missing expiry as "never lifts", so a permanent ban must not
        // hold one - otherwise a later reuse of the column would silently start lifting it.
        $user = User::factory()->create(['status' => AccountStatus::ACTIVE->value]);

        app(BanService::class)->ban(
            $user,
            'Permanent ban must ignore any expiry supplied',
            'permanent',
            now()->addDay()->toDateTimeString(),
        );

        $this->assertNull($user->fresh()->ban_expires_at);
        $this->assertTrue($user->fresh()->isBannedNow());
    }

    /** @test */
    public function unban_clears_every_ban_field_and_returns_the_account_to_logged_out(): void
    {
        $user = $this->bannedUser('temporary', now()->addDay()->toDateTimeString());

        app(BanService::class)->unban($user);
        $fresh = $user->fresh();

        $this->assertSame(AccountStatus::LOGGED_OUT->value, (int) $fresh->status);
        $this->assertNull($fresh->ban_type);
        $this->assertNull($fresh->ban_reason);
        $this->assertNull($fresh->ban_expires_at);
        $this->assertNull($fresh->banned_at);
        $this->assertNull($fresh->banned_by);
        $this->assertFalse($fresh->isBannedNow());
    }

    /**
     * THE CONSOLIDATION PROPERTY. Every cached surface whose value depends on ban state must be
     * cleared, no matter which path performed the lift.
     *
     * Two details that made an earlier draft of this test worthless: the keys are seeded AFTER
     * the ban, because `ban()` itself busts them - seeding first meant the assertions passed on
     * work `ban()` had already done, proving nothing about the lift. And the per-user keys use
     * the real user id, not a literal 1, which belongs to a different user depending on suite
     * order - this test failed only when run as part of the file.
     *
     * @test
     */
    public function the_auto_lift_path_clears_every_ban_dependent_cache(): void
    {
        $user = $this->bannedUser('temporary', now()->subMinute()->toDateTimeString());

        $keys = [
            "auth.user.{$user->id}",
            "admin.user.status.{$user->id}",
            'admin.dashboard.data',
            'admin.dashboard.stats',
            'admin.drivers.dashboard',
            'admin.drivers.stats',
        ];

        // Seeded AFTER the ban, immediately before the lift.
        foreach ($keys as $key) {
            Cache::put($key, 'stale');
        }

        $this->assertTrue(app(BanService::class)->liftExpiredBan($user->id),
            'an expired temporary ban must be lifted');

        foreach ($keys as $key) {
            $this->assertNull(Cache::get($key),
                "the auto-lift path must clear {$key} - the cache the old middleware copy forgot");
        }
    }

    public function the_auto_lift_refuses_when_the_ban_has_not_expired(): void
    {
        $user = $this->bannedUser('temporary', now()->addDay()->toDateTimeString());

        $this->assertFalse(app(BanService::class)->liftExpiredBan($user->id));
        $this->assertSame(AccountStatus::BANNED->value, (int) $user->fresh()->status,
            'a ban that is still in force must survive the auto-lift');
    }

    /** @test */
    public function the_auto_lift_never_touches_a_permanent_ban(): void
    {
        $user = $this->bannedUser('permanent');

        $this->assertFalse(app(BanService::class)->liftExpiredBan($user->id));
        $this->assertTrue($user->fresh()->isBannedNow());
    }

    /** @test */
    public function the_account_status_enum_keeps_the_persisted_integer_values(): void
    {
        // Re-numbering these would be a destructive migration: they are already stored in the
        // database and returned by the admin API.
        $this->assertSame(-1, AccountStatus::BANNED->value);
        $this->assertSame(0, AccountStatus::LOGGED_OUT->value);
        $this->assertSame(1, AccountStatus::ACTIVE->value);
        $this->assertTrue(AccountStatus::ACTIVE->isActive());
        $this->assertFalse(AccountStatus::BANNED->isActive());
        $this->assertFalse(AccountStatus::LOGGED_OUT->isActive());
    }
}
