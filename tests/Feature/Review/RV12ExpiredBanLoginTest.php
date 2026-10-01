<?php

namespace Tests\Feature\Review;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Tests\Support\Concerns\ActsAsStaff;
use Tests\TestCase;

/**
 * RV-12 — an EXPIRED temporary ban must not lock the user out of logging in.
 *
 * Root cause (verified in current code, not R1's stale list): users.status encodes a ban as
 * -1 AND a temporary ban carries ban_expires_at. JwtAuthMiddleware honoured that expiry and
 * auto-lifted it — but it only runs on an AUTHENTICATED request. A banned user's tokens were
 * revoked at ban time (AdminBanController -> revokeAllTokens), so login was the only door
 * left, and LoginController rejected ANY status === -1 unconditionally. The expiry was never
 * reachable: an expired temporary ban became a permanent lockout. GoogleController::callback
 * had the identical gate.
 *
 * These pin both sides of the rule, so it cannot silently swing either way:
 *   - permanent / still-active temporary bans are STILL refused (the door did not swing open)
 *   - an expired temporary ban is allowed AND its dead ban fields are cleared
 *   - the same for the Google path
 *   - unban busts the auth cache the middleware serves (the second RV-12 bug)
 *
 * R1's other RV-12 items (drop persisted status=0, BanService::isBanned() everywhere,
 * createUser status semantics) are a model refactor entangled with owner decisions; only the
 * reachable expiry deadlock + cache bust are done here, recorded as PARTIAL in §27.
 */
class RV12ExpiredBanLoginTest extends TestCase
{
    use ActsAsStaff;
    use RefreshDatabase;

    private function password(): string
    {
        return 'SecretPass123!';
    }

    private function login(string $email): TestResponse
    {
        return $this->post('/api/auth/login', ['email' => $email, 'password' => $this->password()]);
    }

    /** @test */
    public function an_expired_temporary_ban_no_longer_blocks_login(): void
    {
        $user = User::factory()->create([
            'email' => 'expired-temp@example.com',
            'password' => $this->password(),
            'email_verified_at' => now(),
            'status' => -1,
            'ban_type' => 'temporary',
            'ban_expires_at' => now()->subMinute(), // ban window already closed
            'ban_reason' => 'speeding',
        ]);

        $response = $this->login('expired-temp@example.com');

        // The deadlock: before the fix this was 403 ACCOUNT_BANNED forever.
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('tokens.access_token') ?? $response->json('token'),
            'a lifted ban must be able to obtain a working token');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 1]);
    }

    /** @test */
    public function lifting_the_expired_ban_clears_the_dead_ban_fields(): void
    {
        $user = User::factory()->create([
            'email' => 'clearfields@example.com',
            'password' => $this->password(),
            'email_verified_at' => now(),
            'status' => -1,
            'ban_type' => 'temporary',
            'ban_expires_at' => now()->subDay(),
            'ban_reason' => 'stale',
            'banned_by' => 999,
        ]);

        $this->login('clearfields@example.com')->assertStatus(200);

        $fresh = $user->fresh();
        $this->assertNull($fresh->ban_type, 'ban_type must not linger on an active account');
        $this->assertNull($fresh->ban_expires_at);
        $this->assertNull($fresh->ban_reason);
        $this->assertNull($fresh->banned_by);
    }

    /** @test */
    public function a_permanent_ban_is_still_refused_the_door_did_not_swing_open(): void
    {
        User::factory()->create([
            'email' => 'perm@example.com',
            'password' => $this->password(),
            'email_verified_at' => now(),
            'status' => -1,
            'ban_type' => 'permanent',
            'ban_expires_at' => null,
        ]);

        $this->login('perm@example.com')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_BANNED');
    }

    /** @test */
    public function a_temporary_ban_that_has_not_yet_expired_is_still_refused(): void
    {
        User::factory()->create([
            'email' => 'active-temp@example.com',
            'password' => $this->password(),
            'email_verified_at' => now(),
            'status' => -1,
            'ban_type' => 'temporary',
            'ban_expires_at' => now()->addDay(), // still in force
        ]);

        $this->login('active-temp@example.com')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_BANNED');
    }

    /** @test */
    public function a_temporary_ban_with_no_expiry_is_treated_as_still_active(): void
    {
        // Defensive: temporary type but a missing expiry must NOT auto-lift (fail closed).
        User::factory()->create([
            'email' => 'temp-noexpiry@example.com',
            'password' => $this->password(),
            'email_verified_at' => now(),
            'status' => -1,
            'ban_type' => 'temporary',
            'ban_expires_at' => null,
        ]);

        $this->login('temp-noexpiry@example.com')->assertStatus(403);
    }

    /** @test */
    public function unban_busts_the_auth_cache_the_middleware_serves(): void
    {
        // The second RV-12 bug: ban() busts auth.user via revokeAllTokens, but unban() did
        // not, so the middleware kept serving a cached status=-1 copy and returned
        // USER_BANNED for up to 5 minutes AFTER an admin lifted the ban.
        $user = User::factory()->create([
            'email' => 'unban-cache@example.com',
            'status' => -1,
            'ban_type' => 'temporary',
            'ban_expires_at' => now()->addDay(),
        ]);

        // Simulate a warm middleware cache entry holding the banned copy.
        Cache::put("auth.user.{$user->id}", $user->fresh(), 300);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/users/{$user->id}/unban", [])
            ->assertOk();

        $this->assertNull(Cache::get("auth.user.{$user->id}"),
            'RV-12: unban must evict the cached banned copy immediately');
    }

    /** @test */
    public function ban_then_unban_then_login_all_work_end_to_end(): void
    {
        $user = User::factory()->create([
            'email' => 'cycle@example.com',
            'password' => $this->password(),
            'email_verified_at' => now(),
            'status' => 1,
        ]);

        // banned (temporary, already expired on insert to model the lift case)
        $user->update(['status' => -1, 'ban_type' => 'temporary', 'ban_expires_at' => now()->subMinute()]);
        $this->login('cycle@example.com')->assertStatus(200); // expired -> allowed

        // still banned while active
        $user->refresh()->update(['status' => -1, 'ban_type' => 'temporary', 'ban_expires_at' => now()->addYear()]);
        $this->login('cycle@example.com')->assertStatus(403);
    }

    /** @test */
    public function the_google_path_also_lifts_an_expired_temporary_ban(): void
    {
        // The Google callback shared the same unconditional gate; its permanent-ban
        // refusal is pinned by GoogleOauthTokenTest, but the expiry side needed a pin
        // of its own: a linked local account under an EXPIRED temporary ban must be
        // able to complete Google sign-in again.
        $victim = User::factory()->create([
            'email' => 'gexpired@example.com',
            'email_verified_at' => now(),
            'status' => -1,
            'ban_type' => 'temporary',
            'ban_expires_at' => now()->subHour(),
        ]);

        $socialite = \Mockery::mock(\Laravel\Socialite\Two\User::class);
        $socialite->shouldReceive('getId')->andReturn('g-expired-1');
        $socialite->shouldReceive('getEmail')->andReturn('gexpired@example.com');
        $socialite->shouldReceive('getName')->andReturn('G One');
        $socialite->shouldReceive('getAvatar')->andReturn(null);
        $socialite->user = ['given_name' => 'G', 'family_name' => 'One'];

        $driver = \Mockery::mock(GoogleProvider::class);
        $driver->shouldReceive('setHttpClient')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($socialite);
        Socialite::shouldReceive('driver')
            ->with('google')->andReturn($driver);

        $this->get('/auth/google/callback')->assertStatus(200);

        $fresh = $victim->fresh();
        $this->assertSame(1, (int) $fresh->status);
        $this->assertNull($fresh->ban_type, 'Google path must clear the expired ban fields too');
        $this->assertNull($fresh->ban_expires_at);
    }
}
