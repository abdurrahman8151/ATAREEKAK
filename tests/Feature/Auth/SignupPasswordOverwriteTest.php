<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * T2-4 regression — signup must not be a credential-change primitive, and must
 * not echo OTP codes.
 *
 * Before the fix, POST /api/auth/signup against an EXISTING but still-unverified
 * email overwrote that account's password with the request-supplied value and
 * then returned the account's id/first_name/email plus the freshly generated
 * otp_code. Nothing in that path proved the caller controlled the mailbox, so
 * anyone who knew an abandoned address could set the password — a
 * pre-account-takeover: the victim's own password stopped working and the
 * attacker's chosen value became the live credential.
 *
 * LoginController::login() does block unverified accounts (403
 * EMAIL_NOT_VERIFIED), which bounds immediate access, but the credential
 * corruption itself is real and persisted.
 */
class SignupPasswordOverwriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Keep the OTP service off the real SMTP transport. This is the same
        // affordance the existing Auth suites use.
        putenv('EMAIL_OTP_MODE=testing');
    }

    private const PASSWORD_VICTIM = 'original-password-123';
    private const PASSWORD_ATTACKER = 'attacker-chosen-123';

    private function signupPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name'            => 'Ahmad',
            'last_name'             => 'Ali',
            'email'                 => 'victim@test.com',
            'password'              => self::PASSWORD_ATTACKER,
            'password_confirmation' => self::PASSWORD_ATTACKER,
            'gender'                => 'M',
            'address'               => 'دمشق',
        ], $overrides);
    }

    private function unverifiedVictim(): User
    {
        return User::factory()->create([
            'email'             => 'victim@test.com',
            'password'          => Hash::make(self::PASSWORD_VICTIM),
            'email_verified_at' => null,
            'status'            => 0,
        ]);
    }

    // ── The core defect: the password must not change ──────────────────────

    public function test_signup_does_not_overwrite_an_unverified_accounts_password(): void
    {
        $victim = $this->unverifiedVictim();
        $originalHash = $victim->password;

        $this->postJson('/api/auth/signup', $this->signupPayload())
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $victim->refresh();

        // Pre-fix: the hash changed and Hash::check($attackerPassword) was true.
        $this->assertSame($originalHash, $victim->password, 'The stored password hash must be untouched.');
        $this->assertTrue(Hash::check(self::PASSWORD_VICTIM, $victim->password), 'The original password must still work.');
        $this->assertFalse(Hash::check(self::PASSWORD_ATTACKER, $victim->password), 'The attacker-supplied password must NOT become the live credential.');
    }

    public function test_repeated_signup_cannot_reset_the_password(): void
    {
        $victim = $this->unverifiedVictim();
        $originalHash = $victim->password;

        // An attacker hammering the endpoint must never converge on their value.
        foreach (['attacker-a-123', 'attacker-b-123', 'attacker-c-123'] as $attempt) {
            $this->postJson('/api/auth/signup', $this->signupPayload([
                'password'              => $attempt,
                'password_confirmation' => $attempt,
            ]))->assertStatus(200);
        }

        $victim->refresh();
        $this->assertSame($originalHash, $victim->password);
        $this->assertFalse(Hash::check('attacker-c-123', $victim->password));
    }

    public function test_the_victim_can_still_log_in_after_verifying(): void
    {
        $victim = $this->unverifiedVictim();

        $this->postJson('/api/auth/signup', $this->signupPayload())->assertStatus(200);

        // Simulate the legitimate owner completing verification.
        $victim->refresh();
        $victim->update(['email_verified_at' => now(), 'status' => 1]);

        $this->postJson('/api/auth/login', [
            'email'    => 'victim@test.com',
            'password' => self::PASSWORD_VICTIM,
        ])->assertStatus(200)->assertJsonStructure(['tokens' => ['access_token']]);
    }

    public function test_the_attacker_password_is_rejected_at_login(): void
    {
        $victim = $this->unverifiedVictim();

        $this->postJson('/api/auth/signup', $this->signupPayload())->assertStatus(200);

        $victim->refresh();
        $victim->update(['email_verified_at' => now(), 'status' => 1]);

        $this->postJson('/api/auth/login', [
            'email'    => 'victim@test.com',
            'password' => self::PASSWORD_ATTACKER,
        ])->assertStatus(401);
    }

    // ── The OTP echo must be gone ──────────────────────────────────────────

    public function test_signup_never_echoes_the_otp_code_for_an_existing_unverified_email(): void
    {
        putenv('EMAIL_OTP_MODE=testing'); // force the "dev affordance" branch on

        $this->unverifiedVictim();

        $response = $this->postJson('/api/auth/signup', $this->signupPayload());

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonMissingPath('otp_code');
    }

    public function test_signup_never_echoes_the_otp_code_for_a_new_user(): void
    {
        putenv('EMAIL_OTP_MODE=testing');

        $response = $this->postJson('/api/auth/signup', $this->signupPayload([
            'email' => 'brand-new@test.com',
        ]));

        $response->assertStatus(201)
            ->assertJsonMissingPath('otp_code')
            ->assertJsonStructure(['status', 'message', 'user' => ['id', 'email', 'first_name']]);
    }

    // ── Existing behaviour preserved ───────────────────────────────────────

    public function test_a_new_user_is_still_created_unverified(): void
    {
        $this->postJson('/api/auth/signup', $this->signupPayload([
            'email' => 'brand-new@test.com',
        ]))->assertStatus(201);

        $user = User::where('email', 'brand-new@test.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at, 'Email must remain unverified until the OTP is submitted.');
        // NOTE: UserRepository::createUser() hardcodes 'status' => 1, silently
        // discarding the controller's 'status' => 0 (path B). That is a separate
        // pre-existing defect (recorded as a new finding, not part of T2-4); the
        // login gate on email_verified_at is what actually blocks entry.
        $this->assertSame(1, (int) $user->status);
        // The new user's chosen password IS legitimately theirs.
        $this->assertTrue(Hash::check(self::PASSWORD_ATTACKER, $user->password));
    }

    public function test_a_newly_registered_user_cannot_log_in_before_verifying(): void
    {
        $this->postJson('/api/auth/signup', $this->signupPayload([
            'email' => 'brand-new@test.com',
        ]))->assertStatus(201);

        // The real gate: email_verified_at, enforced by LoginController:66.
        $this->postJson('/api/auth/login', [
            'email'    => 'brand-new@test.com',
            'password' => self::PASSWORD_ATTACKER,
        ])->assertStatus(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
    }

    public function test_a_verified_email_is_still_rejected_with_409(): void
    {
        User::factory()->create(['email' => 'taken@test.com', 'email_verified_at' => now()]);

        $this->postJson('/api/auth/signup', $this->signupPayload(['email' => 'taken@test.com']))
            ->assertStatus(409);
    }

    public function test_the_resend_path_still_answers_successfully(): void
    {
        $this->unverifiedVictim();

        // The neutral response keeps the legitimate "I lost the email" flow working.
        $this->postJson('/api/auth/signup', $this->signupPayload())
            ->assertStatus(200)
            ->assertJsonPath('message', 'A new verification code has been sent to your email.');
    }

    public function test_the_neutral_response_does_not_leak_account_identity(): void
    {
        $this->unverifiedVictim();

        $response = $this->postJson('/api/auth/signup', $this->signupPayload());

        // No enumeration aid: the unverified branch must not confirm who the row
        // belongs to (the verified branch still returns a distinct 409).
        $response->assertStatus(200)->assertJsonMissingPath('user');
    }

    public function test_registration_still_requires_a_valid_password_confirmation(): void
    {
        $this->postJson('/api/auth/signup', $this->signupPayload([
            'password_confirmation' => 'mismatch',
        ]))->assertStatus(422)->assertJsonValidationErrors(['password']);
    }
}