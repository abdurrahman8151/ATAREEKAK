<?php

namespace Tests\Feature\Review;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization of POST /api/auth/login (LoginController::__invoke), pinned on the UNCHANGED code
 * before the method is moved.
 *
 * Existing tests cover the ban and unverified branches and the expired-ban rule (RV12, RV42a), but no
 * test pins two contracts by value:
 *  - the refusal for an unknown email and for a wrong password is 401 with the exact message
 *    "Invalid credentials" and code INVALID_CREDENTIALS. Both must stay identical, so the response
 *    does not reveal which of the two failed (account enumeration).
 *  - a correct password on a verified, not-banned account is 200 with the "Login successful" message
 *    and a tokens block.
 *
 * Real route, real repository and real hashing; no mocks.
 */
class LoginContractCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'login.char@example.test';

    private const PASSWORD = 'password123';

    public function test_unknown_email_returns_401_invalid_credentials(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'nobody@example.test', 'password' => self::PASSWORD])
            ->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Invalid credentials')
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_wrong_password_returns_the_same_401_as_an_unknown_email(): void
    {
        User::factory()->create([
            'email' => self::EMAIL,
            'password' => bcrypt(self::PASSWORD),
            'email_verified_at' => now(),
            'status' => 1,
        ]);

        $wrong = $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => 'wrong-password']);
        $unknown = $this->postJson('/api/auth/login', ['email' => 'nobody@example.test', 'password' => 'x']);

        $wrong->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials')
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');

        // Enumeration pin: the two refusals must be indistinguishable in status, message and code.
        $this->assertSame($unknown->getStatusCode(), $wrong->getStatusCode());
        $this->assertSame($unknown->json('message'), $wrong->json('message'));
        $this->assertSame($unknown->json('code'), $wrong->json('code'));
    }

    public function test_correct_password_on_a_verified_account_returns_200_with_login_envelope_and_tokens(): void
    {
        User::factory()->create([
            'email' => self::EMAIL,
            'password' => bcrypt(self::PASSWORD),
            'email_verified_at' => now(),
            'status' => 0,
        ]);

        $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD])
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Login successful')
            ->assertJsonPath('user.email', self::EMAIL)
            ->assertJsonStructure(['tokens']);
    }
}
