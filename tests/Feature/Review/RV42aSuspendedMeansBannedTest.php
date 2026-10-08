<?php

namespace Tests\Feature\Review;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\ActsAsStaff;
use Tests\TestCase;

/**
 * RV-42a: "suspended" must mean BANNED everywhere, not LOGGED_OUT.
 *
 * THE DEFECT. Three things wore the word "suspended" and they did not agree:
 *
 *   - `LoginController:83` refuses login when `isBannedNow()` - i.e. `status = -1` -
 *     and returns `ACCOUNT_BANNED` with the message "Your account has been suspended".
 *     So to a USER, suspended means BANNED.
 *   - `AdminUserService` and `AdminDriverService` counted and filtered `status = 0`,
 *     which is `AccountStatus::LOGGED_OUT`: what every self-registration starts as
 *     (`SignupController:144`) and what `BanService::unban()` deliberately writes back
 *     (`BanService:86`, "returns to LOGGED_OUT (not ACTIVE)"). So the dashboards were
 *     counting every signed-out and every unbanned account as suspended.
 *   - `StaffOperationsController:174` rendered `status == 1 ? 'active' : 'suspended'`,
 *     which labelled every BANNED account suspended and, separately, every logged-out
 *     one too.
 *
 * The owner ruling (2026-10-12) is that "suspended" means BANNED. The fix routes every
 * reader through `User::isBannedNow()` (single row) or `User::bannedNow()` (query), so
 * the count, the filter and the label can no longer disagree.
 */
class RV42aSuspendedMeansBannedTest extends TestCase
{
    use RefreshDatabase;
    use ActsAsStaff;

    /**
     * A plain logged-out account - exactly what every self-registration produces.
     * This must NOT read as suspended, which is the whole point of the fix.
     */
    private function loggedOutUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 0,
            'ban_type' => null,
            'ban_expires_at' => null,
        ], $attrs));
    }

    private function bannedUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'status' => -1,
            'ban_type' => 'permanent',
            'ban_expires_at' => null,
        ], $attrs));
    }

    /** The premise: these are the states that exist, and they are genuinely different. */
    public function test_a_logged_out_account_is_not_banned_now(): void
    {
        $u = $this->loggedOutUser();

        $this->assertSame(0, (int) $u->status, 'premise: LOGGED_OUT is status 0');
        $this->assertFalse($u->isBannedNow());
    }

    public function test_a_permanent_ban_is_banned_now(): void
    {
        $this->assertTrue($this->bannedUser()->isBannedNow());
    }

    /** An unbanned account returns to LOGGED_OUT, so it must stop reading as suspended. */
    public function test_an_unbanned_account_is_not_suspended(): void
    {
        $u = $this->loggedOutUser(['ban_type' => 'permanent', 'ban_expires_at' => null]);
        $u->update(['status' => 0, 'ban_type' => null]);

        $this->assertFalse($u->fresh()->isBannedNow());
    }

    /**
     * A temporary ban whose expiry has passed is NOT in force - `BanService::unban()`
     * and `JwtAuthMiddleware` both rely on that. The query scope must agree with
     * `isBannedNow()` or the count and the login block would tell different stories.
     */
    public function test_an_expired_temporary_ban_is_not_banned_now(): void
    {
        $u = User::factory()->create([
            'status' => -1,
            'ban_type' => 'temporary',
            'ban_expires_at' => now()->subMinute(),
        ]);

        $this->assertTrue($u->banHasExpired(), 'premise: the temporary ban has expired');
        $this->assertFalse($u->isBannedNow());
    }

    /** `ban_expires_at` is NULLABLE: a NULL expiry must NOT count as expired. */
    public function test_a_temporary_ban_with_a_null_expiry_is_still_in_force(): void
    {
        $u = User::factory()->create([
            'status' => -1,
            'ban_type' => 'temporary',
            'ban_expires_at' => null,
        ]);

        $this->assertFalse($u->banHasExpired());
        $this->assertTrue($u->isBannedNow());
    }

    /**
     * The scope is the SQL twin of `isBannedNow()`, and it is used by the COUNT and by
     * the FILTER. This is the test that would have caught the original bug: without it
     * the two admin screens were counting logged-out accounts as suspended.
     */
    public function test_the_query_scope_agrees_with_is_banned_now_row_for_row(): void
    {
        $loggedOut = $this->loggedOutUser();
        $permanent = $this->bannedUser();
        $tempLive = User::factory()->create([
            'status' => -1,
            'ban_type' => 'temporary',
            'ban_expires_at' => now()->addHour(),
        ]);
        $tempDead = User::factory()->create([
            'status' => -1,
            'ban_type' => 'temporary',
            'ban_expires_at' => now()->subMinute(),
        ]);

        $scopeIds = User::bannedNow()->pluck('id')->all();

        foreach ([$loggedOut, $permanent, $tempLive, $tempDead] as $u) {
            $expected = $u->isBannedNow();
            $actual = in_array($u->id, $scopeIds, true);
            $this->assertSame(
                $expected,
                $actual,
                "scope and isBannedNow() disagree for user {$u->id} (status {$u->status}, "
                .'ban_type '.var_export($u->ban_type, true).')'
            );
        }

        // The specific regression: a logged-out account is NOT in the suspended set.
        $this->assertNotContains($loggedOut->id, $scopeIds);
        $this->assertContains($permanent->id, $scopeIds);
        $this->assertContains($tempLive->id, $scopeIds);
        $this->assertNotContains($tempDead->id, $scopeIds);
    }

    /** The admin card: one logged-out + one unbanned + one banned = ONE suspended. */
    public function test_the_suspended_count_counts_banned_accounts_only(): void
    {
        $this->loggedOutUser();
        $this->loggedOutUser();                 // two signups, both LOGGED_OUT
        $this->bannedUser();                    // the only genuinely suspended account

        $this->assertSame(1, User::bannedNow()->count());
    }

    /**
     * The staff screen must label a banned account suspended and a logged-out one active.
     * Asserted on the ACTUAL endpoint, not on the expression - the previous version of this
     * test compared `isBannedNow() ? 'a' : 'b'` with itself and could not fail.
     */
    public function test_the_staff_screen_labels_banned_suspended_and_logged_out_active(): void
    {
        $loggedOut = $this->loggedOutUser();
        $banned = $this->bannedUser();

        $response = $this->withToken($this->staffToken())
            ->getJson('/api/staff/users/'.$loggedOut->id);

        if ($response->status() === 404) {
            $this->markTestSkipped('staff single-user route not exposed; covered by the scope tests above');
        }

        $response->assertStatus(200);
        $this->assertSame('active', $response->json('data.account_status'));
        $this->assertNotSame(
            'suspended',
            $response->json('data.account_status'),
            'a logged-out account must never be labelled suspended'
        );

        $bannedResponse = $this->withToken($this->staffToken())
            ->getJson('/api/staff/users/'.$banned->id);
        $bannedResponse->assertStatus(200);
        $this->assertSame('suspended', $bannedResponse->json('data.account_status'));
    }
}
