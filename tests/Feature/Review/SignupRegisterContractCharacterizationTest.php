<?php

namespace Tests\Feature\Review;

use App\DTOs\Auth\SendEmailOtpDTO;
use App\DTOs\Auth\VerifyEmailOtpDTO;
use App\Models\User;
use App\Modules\Identity\Contracts\EmailOtpServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Characterization of POST /api/auth/signup (SignupController::register), pinned on the UNCHANGED code
 * before the method is moved.
 *
 * Pins the contracts a refactor could break and that no existing test checks by sentence or by body:
 *  - PATH A, unverified account: a re-registration resends the code and must NOT overwrite the stored
 *    password (the pre-account-takeover rule in the controller). The password check is the security pin.
 *  - PATH A, verified account: 409 with the exact sentence.
 *  - PATH B, success: 201, the user block, and no otp_code in the body (the code is delivered by mail only).
 *  - Validation: 422 with status "error" and message "Validation failed".
 *
 * Only the outbound mailer is faked (the EmailOtpServiceInterface seam, as in RV16). The repository, the
 * database and the password hashing are real.
 */
class SignupRegisterContractCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'signup.char@example.test';

    private object $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailer = new class implements EmailOtpServiceInterface
        {
            /** @return array<string, mixed> */
            public function sendOtp(SendEmailOtpDTO $dto): array
            {
                return ['success' => true, 'message' => 'Verification code sent to your email.'];
            }

            /** @return array<string, mixed> */
            public function verifyOtp(VerifyEmailOtpDTO $dto): array
            {
                return ['success' => true, 'message' => 'ok'];
            }
        };

        $this->app->instance(EmailOtpServiceInterface::class, $this->mailer);
    }

    /** @return array<string, string> */
    private function payload(string $password = 'password123'): array
    {
        return [
            'first_name' => 'Sig',
            'last_name' => 'Char',
            'email' => self::EMAIL,
            'password' => $password,
            'password_confirmation' => $password,
            'gender' => 'M',
            'address' => 'دمشق',
        ];
    }

    public function test_new_registration_returns_201_with_user_block_and_no_otp_code(): void
    {
        $response = $this->postJson('/api/auth/signup', $this->payload());

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Registration successful. Check your email for a verification code.')
            ->assertJsonPath('user.email', self::EMAIL)
            ->assertJsonMissingPath('otp_code');

        $this->assertStringNotContainsString('otp_code', $response->getContent());
    }

    public function test_reregistering_an_unverified_email_resends_the_code_and_keeps_the_stored_password(): void
    {
        $this->postJson('/api/auth/signup', $this->payload('original-pass1'))->assertStatus(201);

        $response = $this->postJson('/api/auth/signup', $this->payload('attacker-pass2'));

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'A new verification code has been sent to your email.');

        // The security pin: the stored credential must still be the ORIGINAL one.
        $stored = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertTrue(Hash::check('original-pass1', $stored->password), 'Stored password was overwritten.');
        $this->assertFalse(Hash::check('attacker-pass2', $stored->password), 'Attacker-chosen password became live.');
    }

    public function test_registering_an_already_verified_email_returns_409_with_the_exact_sentence(): void
    {
        $this->postJson('/api/auth/signup', $this->payload())->assertStatus(201);
        User::where('email', self::EMAIL)->update(['email_verified_at' => now()]);

        $this->postJson('/api/auth/signup', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'This email address is already registered. Please log in.');
    }

    public function test_validation_failure_returns_422_with_the_validation_envelope(): void
    {
        $payload = $this->payload();
        $payload['gender'] = 'X';

        $this->postJson('/api/auth/signup', $payload)
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Validation failed')
            ->assertJsonStructure(['errors' => ['gender']]);
    }
}
