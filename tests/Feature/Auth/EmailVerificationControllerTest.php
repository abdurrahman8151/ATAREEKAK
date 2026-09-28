<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailVerificationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('EMAIL_OTP_MODE=testing');
    }

    // ─── send ──────────────────────────────────────────────────────────────

    public function test_can_send_otp_to_existing_unverified_email(): void
    {
        User::factory()->create(['email' => 'user@test.com', 'email_verified_at' => null]);

        $this->postJson('/api/email-verification/send', ['email' => 'user@test.com'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_send_exposes_otp_code_in_testing_mode(): void
    {
        User::factory()->create(['email' => 'user@test.com', 'email_verified_at' => null]);

        $response = $this->postJson('/api/email-verification/send', ['email' => 'user@test.com']);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('otp_code'));
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $response->json('otp_code'));
    }

    public function test_send_fails_with_invalid_email_format(): void
    {
        $this->postJson('/api/email-verification/send', ['email' => 'not-an-email'])
            ->assertStatus(422);
    }

    public function test_send_fails_with_missing_email(): void
    {
        $this->postJson('/api/email-verification/send', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // ─── verify ────────────────────────────────────────────────────────────

    public function test_can_verify_valid_otp_and_receive_jwt_tokens(): void
    {
        User::factory()->create([
            'email' => 'user@test.com',
            'email_verified_at' => null,
        ]);

        $otp = $this->postJson('/api/email-verification/send', ['email' => 'user@test.com'])
            ->json('otp_code');

        $this->assertNotNull($otp, 'Testing env should expose otp_code – check EmailOtpService.');

        $this->postJson('/api/email-verification/verify', [
            'email' => 'user@test.com',
            'otp_code' => $otp,
        ])->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'tokens' => ['access_token', 'refresh_token'],
                'user' => ['id', 'email'],
            ]);
    }

    public function test_verify_sets_email_verified_at_on_the_user(): void
    {
        $user = User::factory()->create([
            'email' => 'user@test.com',
            'email_verified_at' => null,
        ]);

        $otp = $this->postJson('/api/email-verification/send', ['email' => 'user@test.com'])
            ->json('otp_code');

        $this->postJson('/api/email-verification/verify', [
            'email' => 'user@test.com',
            'otp_code' => $otp,
        ]);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verify_fails_with_wrong_otp_code(): void
    {
        User::factory()->create(['email' => 'user@test.com', 'email_verified_at' => null]);

        $this->postJson('/api/email-verification/send', ['email' => 'user@test.com']);

        $this->postJson('/api/email-verification/verify', [
            'email' => 'user@test.com',
            'otp_code' => '000000',
        ])->assertStatus(400);
    }

    public function test_verify_fails_with_missing_fields(): void
    {
        $this->postJson('/api/email-verification/verify', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'otp_code']);
    }

    public function test_verify_fails_for_unknown_email(): void
    {
        // FIX: VerifyEmailOtpRequest has 'exists:users,email' rule, so unknown
        // emails are rejected at validation (422), not at the service level.
        $this->postJson('/api/email-verification/verify', [
            'email' => 'nobody@test.com',
            'otp_code' => '123456',
        ])->assertStatus(422);
    }

    public function test_verify_otp_code_must_be_exactly_six_digits(): void
    {
        User::factory()->create(['email' => 'user@test.com']);

        $this->postJson('/api/email-verification/verify', [
            'email' => 'user@test.com',
            'otp_code' => '123',
        ])->assertStatus(422);
    }

    public function test_verify_otp_code_must_be_numeric(): void
    {
        User::factory()->create(['email' => 'user@test.com']);

        $this->postJson('/api/email-verification/verify', [
            'email' => 'user@test.com',
            'otp_code' => 'abcdef',
        ])->assertStatus(422);
    }

    // ─── resend ────────────────────────────────────────────────────────────

    public function test_resend_sends_new_otp_for_unverified_user(): void
    {
        User::factory()->create([
            'email' => 'user@test.com',
            'email_verified_at' => null,
        ]);

        $this->postJson('/api/email-verification/resend', ['email' => 'user@test.com'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_resend_exposes_otp_in_testing_mode(): void
    {
        User::factory()->create(['email' => 'user@test.com', 'email_verified_at' => null]);

        $response = $this->postJson('/api/email-verification/resend', ['email' => 'user@test.com']);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('otp_code'));
    }

    public function test_resend_returns_404_for_nonexistent_email(): void
    {
        $this->postJson('/api/email-verification/resend', ['email' => 'nobody@test.com'])
            ->assertStatus(404);
    }

    public function test_resend_returns_409_if_email_already_verified(): void
    {
        User::factory()->create([
            'email' => 'user@test.com',
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/email-verification/resend', ['email' => 'user@test.com'])
            ->assertStatus(409);
    }

    public function test_resend_fails_with_invalid_email_format(): void
    {
        $this->postJson('/api/email-verification/resend', ['email' => 'not-an-email'])
            ->assertStatus(422);
    }

    public function test_resend_new_otp_can_be_used_to_verify(): void
    {
        User::factory()->create(['email' => 'user@test.com', 'email_verified_at' => null]);

        $otp = $this->postJson('/api/email-verification/resend', ['email' => 'user@test.com'])
            ->json('otp_code');

        $this->postJson('/api/email-verification/verify', [
            'email' => 'user@test.com',
            'otp_code' => $otp,
        ])->assertStatus(200)
            ->assertJsonPath('success', true);
    }
}
