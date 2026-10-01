<?php

namespace Tests\Feature\Review;

use App\Models\Employee;
use App\Models\RefreshToken;
use App\Models\StaffRefreshToken;
use App\Models\User;
use App\Services\JwtService;
use App\Services\Staff\StaffJwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RV-29 (slice 1) — refresh-token REUSE DETECTION revokes the whole lineage.
 *
 * Before: refreshAccessToken() filtered the lookup on revoked=false, so replaying a
 * consumed token returned null with no signal. If a refresh token was stolen, the thief's
 * replay succeeded FIRST and the rotation marked the legit user's copy revoked; the user's
 * next attempt then also returned null — indistinguishable from any other invalid token.
 * Nobody learned that theft happened, and the attacker's already-issued access tokens
 * stayed valid until TTL.
 *
 * Now: a revoked-but-unexpired token presented again = two live copies of one secret =
 * theft. The service logs it and calls revokeAllTokens() — revoking every refresh row AND
 * bumping token_version so outstanding ACCESS tokens die too. An expired row replay is
 * NOT treated as reuse (nothing left to protect), and normal single rotation is unchanged.
 *
 * Both audiences are pinned (user JwtService + staff StaffJwtService) because both had the
 * identical defect and both guard money-capable sessions.
 */
class RV29RefreshReuseDetectionTest extends TestCase
{
    use RefreshDatabase;

    private JwtService $jwt;

    private StaffJwtService $staffJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jwt = app(JwtService::class);
        $this->staffJwt = app(StaffJwtService::class);
    }

    private function employee(): Employee
    {
        return Employee::create([
            'username' => 'reuse_'.uniqid(),
            'email' => 'reuse'.uniqid().'@test.com',
            'password' => 'Password123!',
            'first_name' => 'Re',
            'last_name' => 'Use',
            'role' => 'system_admin',
            'is_active' => true,
            'token_version' => 0,
        ]);
    }

    /** @test */
    public function normal_rotation_still_works_for_the_legitimate_holder(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $raw = $this->jwt->generateTokenPair($user)['refresh_token'];

        $first = $this->jwt->refreshAccessToken($raw);
        $this->assertNotNull($first, 'a valid refresh must rotate');

        // The NEW refresh from rotation must itself work once (single-use per token).
        $second = $this->jwt->refreshAccessToken($first['refresh_token']);
        $this->assertNotNull($second, 'the rotated token must be usable once normally');
    }

    /** @test */
    public function replaying_a_consumed_user_token_revokes_the_entire_family(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $raw = $this->jwt->generateTokenPair($user)['refresh_token'];

        $rotated = $this->jwt->refreshAccessToken($raw);
        $this->assertNotNull($rotated);

        // A SECOND live refresh for the same user (e.g. another device / the thief's copy).
        $other = $this->jwt->generateTokenPair($user)['refresh_token'];

        // THE REPLAY: present the already-consumed token again.
        $replayed = $this->jwt->refreshAccessToken($raw);
        $this->assertNull($replayed, 'a consumed token must never mint new pairs');

        // Reuse signal => EVERYTHING for that user is dead: the other active refresh too.
        $stillActive = RefreshToken::where('user_id', $user->id)
            ->where('revoked', false)
            ->where('expires_at', '>', now())
            ->count();
        $this->assertSame(
            0,
            $stillActive,
            'RV-29: reuse must revoke the whole refresh family, not just the replayed row'
        );

        // And the live "other" token now fails too — proof the family is gone.
        $this->assertNull($this->jwt->refreshAccessToken($other));
    }

    /** @test */
    public function reuse_detection_bumps_token_version_so_live_access_tokens_die(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $raw = $this->jwt->generateTokenPair($user)['refresh_token'];

        $beforeVersion = (int) $user->fresh()->token_version;

        $this->jwt->refreshAccessToken($raw);
        $this->jwt->generateTokenPair($user);          // a second lineage copy
        $this->jwt->refreshAccessToken($raw);          // replay -> reuse -> revoke all

        $afterVersion = (int) $user->fresh()->token_version;
        $this->assertGreaterThan(
            $beforeVersion,
            $afterVersion,
            'RV-29: reuse must invalidate outstanding ACCESS tokens via token_version bump'
        );
    }

    /** @test */
    public function an_expired_replay_is_not_mistaken_for_theft(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $raw = $this->jwt->generateTokenPair($user)['refresh_token'];
        $other = $this->jwt->generateTokenPair($user)['refresh_token'];

        // Consumed AND already past expiry: replaying it protects nothing.
        $this->jwt->refreshAccessToken($raw);
        RefreshToken::where('token', hash('sha256', $raw))
            ->update(['expires_at' => now()->subDay()]);

        $this->assertNull($this->jwt->refreshAccessToken($raw));

        // The unrelated live token must SURVIVE — no family kill on an expired ghost.
        $this->assertNotNull(
            $this->jwt->refreshAccessToken($other),
            'RV-29: an expired replay must not nuke a healthy session'
        );
    }

    /** @test */
    public function staff_token_replay_also_revokes_the_whole_staff_family(): void
    {
        $emp = $this->employee();
        $raw = $this->staffJwt->generateTokenPair($emp)['refresh_token'];

        $rotated = $this->staffJwt->refreshAccessToken($raw);
        $this->assertNotNull($rotated);
        $other = $this->staffJwt->generateTokenPair($emp)['refresh_token'];

        $this->assertNull(
            $this->staffJwt->refreshAccessToken($raw),
            'consumed staff token must not mint pairs'
        );

        $this->assertSame(
            0,
            StaffRefreshToken::where('employee_id', $emp->id)
                ->where('revoked', false)
                ->where('expires_at', '>', now())
                ->count(),
            'RV-29 staff: reuse must revoke every active refresh row of the employee'
        );

        $this->assertNull($this->staffJwt->refreshAccessToken($other),
            'the healthy sibling must die too — one lineage, one compromise');
    }

    /** @test */
    public function staff_reuse_bumps_employee_token_version(): void
    {
        $emp = $this->employee();
        $raw = $this->staffJwt->generateTokenPair($emp)['refresh_token'];
        $before = (int) $emp->fresh()->token_version;

        $this->staffJwt->refreshAccessToken($raw);
        $this->staffJwt->generateTokenPair($emp);
        $this->staffJwt->refreshAccessToken($raw);   // replay -> reuse

        $this->assertGreaterThan(
            $before,
            (int) $emp->fresh()->token_version,
            'RV-29 staff: reuse must invalidate outstanding staff access tokens'
        );
    }
}
