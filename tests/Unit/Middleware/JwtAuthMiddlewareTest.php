<?php

namespace Tests\Unit\Middleware;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * JwtAuthMiddlewareTest
 *
 * Tests the JwtAuthMiddleware (alias: 'jwt') behavior through the
 * /api/user route, which is protected by this middleware.
 *
 * Status semantics enforced by the middleware:
 *   status -1 = banned  → 403 USER_BANNED  (only /api/contact allowed)
 *   status  0 = logged out → 401 USER_INACTIVE
 *   status  1 = active     → pass through
 *
 * Token version: bumped on logout-all or password reset. Tokens
 * issued before the bump are rejected with TOKEN_INVALIDATED.
 */
class JwtAuthMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    // ─── Missing / malformed token ─────────────────────────────────────────

    public function test_missing_token_returns_401(): void
    {
        $this->getJson('/api/user')
            ->assertStatus(401)
            ->assertJsonPath('code', 'TOKEN_MISSING');
    }

    public function test_invalid_token_string_returns_401(): void
    {
        $this->withToken('not.a.valid.jwt')
            ->getJson('/api/user')
            ->assertStatus(401)
            ->assertJsonPath('code', 'TOKEN_INVALID');
    }

    public function test_basic_auth_header_not_accepted(): void
    {
        $this->getJson('/api/user', ['Authorization' => 'Basic abc123'])
            ->assertStatus(401);
    }

    public function test_empty_bearer_token_returns_401(): void
    {
        $this->withToken('')
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    // ─── Valid token ───────────────────────────────────────────────────────

    public function test_valid_token_passes_through_to_route(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $token = $this->loginAndGetToken($user);

        $this->withToken($token)
            ->getJson('/api/user')
            ->assertStatus(200)
            ->assertJsonPath('user.id', $user->id);
    }

    // ─── Banned user (status = -1) ─────────────────────────────────────────

    public function test_banned_user_receives_403_on_protected_routes(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $token = $this->loginAndGetToken($user);

        $user->update(['status' => -1]);

        $this->withToken($token)
            ->getJson('/api/user')
            ->assertStatus(403)
            ->assertJsonPath('code', 'USER_BANNED');
    }

    public function test_banned_user_response_includes_ban_details(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $token = $this->loginAndGetToken($user);

        $user->update(['status' => -1, 'ban_reason' => 'Violation of terms']);

        $response = $this->withToken($token)->getJson('/api/user');

        $response->assertStatus(403);
        $this->assertArrayHasKey('ban', $response->json());
    }

    // ─── Inactive user (status = 0) ────────────────────────────────────────

    public function test_logged_out_user_receives_401_user_inactive(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $token = $this->loginAndGetToken($user);

        $user->update(['status' => 0]);

        $this->withToken($token)
            ->getJson('/api/user')
            ->assertStatus(401)
            ->assertJsonPath('code', 'USER_INACTIVE');
    }

    // ─── Token version invalidation ────────────────────────────────────────

    public function test_old_token_rejected_after_token_version_bump(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $token = $this->loginAndGetToken($user);

        // Simulate logout-all: bump token_version so old tokens are stale
        $user->increment('token_version');

        $this->withToken($token)
            ->getJson('/api/user')
            ->assertStatus(401)
            ->assertJsonPath('code', 'TOKEN_INVALIDATED');
    }

    // ─── Refresh token used as access ──────────────────────────────────────

    public function test_using_refresh_token_as_access_token_returns_401(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $refreshToken = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json('tokens.refresh_token');

        // R2 sec 121 (owner decision 2026-10-12): the ratified code is TOKEN_INVALID, not
        // TOKEN_TYPE_INVALID. TOKEN_TYPE_INVALID is UNREACHABLE for a refresh token: login mints an
        // OPAQUE Str::random(64) (JwtService::generateRefreshToken), not a JWT, so it fails at
        // decodeToken (JwtAuthMiddleware:36) and never reaches the type check on line 41. The denial
        // was always a correct 401; only the public code differed.
        $this->withToken($refreshToken)
            ->getJson('/api/user')
            ->assertStatus(401)
            ->assertJsonPath('code', 'TOKEN_INVALID');
    }

    // ─── Helper ────────────────────────────────────────────────────────────

    private function loginAndGetToken(User $user): string
    {
        $user->update(['password' => bcrypt('password123')]);

        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json('tokens.access_token');
    }
}
