<?php

namespace Tests\Feature\Review;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization of JwtAuthMiddleware refusals on a real protected route (GET /api/user), pinned on the
 * UNCHANGED middleware before the class is moved.
 *
 * Existing tests pin the status codes and several codes (TOKEN_INVALID, USER_BANNED, USER_INACTIVE,
 * TOKEN_INVALIDATED, the RV-04 staff-token guard), but not these three contracts by value:
 *  - no Authorization header -> 401, code TOKEN_MISSING, message "Unauthenticated".
 *  - a signed token that does not decode (garbage) -> 401, code TOKEN_INVALID, message
 *    "Invalid or expired token".
 *  - a validly signed access token whose subject no longer exists -> 401, code USER_NOT_FOUND.
 *
 * Real route, real JWT signing and decoding, real database. No mocks.
 */
class JwtAuthMiddlewareRefusalContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_authorization_header_returns_401_token_missing(): void
    {
        $this->getJson('/api/user')
            ->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'TOKEN_MISSING')
            ->assertJsonPath('message', 'Unauthenticated');
    }

    public function test_undecodable_bearer_token_returns_401_token_invalid_with_the_exact_message(): void
    {
        $this->getJson('/api/user', ['Authorization' => 'Bearer not.a.real.token'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'TOKEN_INVALID')
            ->assertJsonPath('message', 'Invalid or expired token');
    }

    public function test_valid_token_for_a_deleted_user_returns_401_user_not_found(): void
    {
        $user = User::factory()->create([
            'status' => 1,
            'email_verified_at' => now(),
        ]);

        $token = app(JwtService::class)->generateTokenPair($user)['access_token'];

        $this->assertNotEmpty($token, 'a real access token must be issued for the characterization');

        // The signature stays valid; only the subject disappears.
        $user->forceDelete();

        $this->getJson('/api/user', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(401)
            ->assertJsonPath('code', 'USER_NOT_FOUND')
            ->assertJsonPath('message', 'User not found');
    }
}
