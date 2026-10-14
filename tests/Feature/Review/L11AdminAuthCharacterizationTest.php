<?php

namespace Tests\Feature\Review;

use App\Enums\StaffRole;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L11 (modular-monolith slice 10, AUTH): characterization test for POST /api/admin/login and
 * POST /api/admin/refresh.
 *
 * Step 1 of the login/refresh change. The existing tests cover login only partly (RV43 checks
 * a valid login and invalid credentials). No test calls /api/admin/refresh at all. This file pins
 * the current behaviour on the UNCHANGED code: the login response shape, the bad-credential and
 * validation paths, and the refresh success, token rotation, and invalid-token refusal.
 * It changes no application code and must pass before any step 2 change is considered.
 */
class L11AdminAuthCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password123!';

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::create([
            'username' => 'l11_admin',
            'email' => 'l11_admin@test.com',
            'password' => self::PASSWORD,
            'first_name' => 'Layla',
            'last_name' => 'Probe',
            'role' => StaffRole::SYSTEM_ADMIN->value,
            'is_active' => true,
            'token_version' => 0,
        ]);
    }

    public function test_login_returns_the_admin_and_a_token_pair(): void
    {
        $response = $this->login()->assertOk();

        $this->assertSame('success', $response->json('status'));
        $this->assertSame('Login successful', $response->json('message'));
        $this->assertSame('system_admin', $response->json('admin.role'));
        $this->assertNotEmpty($response->json('tokens.access_token'));
        $this->assertNotEmpty($response->json('tokens.refresh_token'));
    }

    public function test_login_by_email_is_accepted_as_well_as_username(): void
    {
        $this->postJson('/api/admin/login', [
            'email' => 'l11_admin@test.com',
            'password' => self::PASSWORD,
        ])->assertOk();
    }

    public function test_wrong_password_is_refused_with_invalid_credentials(): void
    {
        $this->postJson('/api/admin/login', [
            'username' => 'l11_admin',
            'password' => 'wrong-password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS')
            ->assertJsonMissingPath('tokens');
    }

    public function test_unknown_user_is_refused_with_the_same_invalid_credentials_response(): void
    {
        $this->postJson('/api/admin/login', [
            'username' => 'no_such_admin',
            'password' => self::PASSWORD,
        ])
            ->assertStatus(401)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_login_without_an_identifier_fails_validation(): void
    {
        $this->postJson('/api/admin/login', ['password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_refresh_issues_a_new_access_token_for_a_valid_refresh_token(): void
    {
        $refresh = $this->login()->json('tokens.refresh_token');

        $response = $this->postJson('/api/admin/refresh', ['refresh_token' => $refresh])->assertOk();

        $this->assertSame('success', $response->json('status'));
        $this->assertNotEmpty($response->json('tokens.access_token'));
    }

    public function test_refresh_rotates_the_refresh_token(): void
    {
        $original = $this->login()->json('tokens.refresh_token');

        $rotated = $this->postJson('/api/admin/refresh', ['refresh_token' => $original])
            ->assertOk()
            ->json('tokens.refresh_token');

        $this->assertNotEmpty($rotated);
        $this->assertNotSame($original, $rotated, 'a refresh must hand back a new refresh token');
    }

    public function test_an_invalid_refresh_token_is_refused(): void
    {
        $this->postJson('/api/admin/refresh', ['refresh_token' => 'not-a-real-token'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'REFRESH_TOKEN_INVALID');
    }

    public function test_refresh_without_a_token_fails_validation(): void
    {
        $this->postJson('/api/admin/refresh', [])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    private function login()
    {
        return $this->postJson('/api/admin/login', [
            'username' => 'l11_admin',
            'password' => self::PASSWORD,
        ]);
    }
}
