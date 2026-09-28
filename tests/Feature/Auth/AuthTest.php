<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AuthTest — Feature tests for authentication endpoints.
 *
 * HOW FEATURE TESTS WORK:
 * - RefreshDatabase rolls back ALL database changes after each test
 *   so tests are completely isolated from each other
 * - $this->postJson() simulates an HTTP request to your API
 * - assertStatus() checks the HTTP response code
 * - assertJsonStructure() checks the shape of the JSON response
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    // ─── Signup ───────────────────────────────────────────────────────────────

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/auth/signup', [
            'first_name' => 'Ahmad',
            'last_name' => 'Ali',
            'email' => 'ahmad@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'gender' => 'M',
            'address' => 'دمشق',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'status',
                'message',
                'user' => ['id', 'email', 'first_name'],
                // no 'tokens' — signup only sends an OTP; tokens come from
                // POST /api/email-verification/verify after the code is confirmed.
            ]);
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create([
            'email' => 'ahmad@test.com',
            'email_verified_at' => now(), // FIX: must be verified to trigger the 409 hard-stop branch
        ]);

        $response = $this->postJson('/api/auth/signup', [
            'first_name' => 'Ahmad',
            'last_name' => 'Ali',
            'email' => 'ahmad@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'gender' => 'M',
            'address' => 'دمشق',
        ]);

        // FIX: SignupController intentionally returns 409 Conflict for an
        // already-verified duplicate email, not a 422 validation error
        $response->assertStatus(409);
    }

    public function test_registration_fails_with_invalid_address(): void
    {
        $response = $this->postJson('/api/auth/signup', [
            'first_name' => 'Ahmad',
            'last_name' => 'Ali',
            'email' => 'ahmad@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'gender' => 'M',
            'address' => 'New York', // not a Syrian city
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['address']);
    }

    public function test_registration_fails_password_mismatch(): void
    {
        $response = $this->postJson('/api/auth/signup', [
            'first_name' => 'Ahmad',
            'last_name' => 'Ali',
            'email' => 'ahmad@test.com',
            'password' => 'password123',
            'password_confirmation' => 'different',
            'gender' => 'M',
            'address' => 'دمشق',
        ]);

        $response->assertStatus(422);
    }

    // ─── Login ────────────────────────────────────────────────────────────────

    public function test_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'test@test.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'tokens' => ['access_token', 'refresh_token', 'access_token_expires_at'],
                'user' => ['id', 'email'],
            ]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'test@test.com',
            'password' => bcrypt('correct_password'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@test.com',
            'password' => 'wrong_password',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_fails_with_nonexistent_email(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nobody@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_requires_email_and_password(): void
    {
        $response = $this->postJson('/api/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    // ─── Logout ───────────────────────────────────────────────────────────────

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAndGetToken($user);

        $response = $this->withToken($token)
            ->postJson('/api/logout');

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);
    }

    public function test_logout_requires_authentication(): void
    {
        $response = $this->postJson('/api/logout');
        $response->assertStatus(401);
    }

    // ─── Token refresh ────────────────────────────────────────────────────────

    public function test_can_refresh_token(): void
    {
        $user = User::factory()->create([
            'email' => 'test@test.com',
            'password' => bcrypt('password123'),
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'test@test.com',
            'password' => 'password123',
        ]);

        $refreshToken = $loginResponse->json('tokens.refresh_token');

        $response = $this->postJson('/api/auth/refresh', [
            'refresh_token' => $refreshToken,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'tokens' => ['access_token', 'refresh_token'],
            ]);
    }

    public function test_refresh_fails_with_invalid_token(): void
    {
        $response = $this->postJson('/api/auth/refresh', [
            'refresh_token' => 'invalid_token_string',
        ]);

        $response->assertStatus(401);
    }

    // ─── Protected routes ─────────────────────────────────────────────────────

    public function test_protected_route_requires_token(): void
    {
        $response = $this->getJson('/api/user');
        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_get_own_info(): void
    {
        $user = User::factory()->create();
        $token = $this->loginAndGetToken($user);

        $response = $this->withToken($token)->getJson('/api/user');

        $response->assertStatus(200)
            ->assertJsonPath('user.id', $user->id);
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    private function loginAndGetToken(User $user): string
    {
        $user->update(['password' => bcrypt('password123')]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        return $response->json('tokens.access_token');
    }
}
