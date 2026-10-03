<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResetPasswordControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('otp.email_mode', 'testing');
        $this->user = User::factory()->create([
            'email' => 'reset@test.com',
            'password' => bcrypt('old_password'),
        ]);
    }

    // ─── Step 1: forgot ─────────────────────────────────────────────────
    public function test_forgot_password_sends_otp_for_existing_email(): void
    {
        $this->postJson('/api/auth/password/forgot', ['email' => 'reset@test.com'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_forgot_password_fails_for_nonexistent_email(): void
    {
        $this->postJson('/api/auth/password/forgot', ['email' => 'nobody@test.com'])
            ->assertStatus(404);
    }

    public function test_forgot_password_requires_valid_email_format(): void
    {
        $this->postJson('/api/auth/password/forgot', ['email' => 'not-an-email'])
            ->assertStatus(422);
    }

    // ─── Step 2: verify-otp ─────────────────────────────────────────────
    public function test_verify_otp_returns_reset_token_for_correct_code(): void
    {
        $otp = $this->postJson('/api/auth/password/forgot', ['email' => 'reset@test.com'])->json('otp_code');
        $this->assertNotNull($otp, 'Testing env should expose otp_code — check EmailOtpService.');

        $this->postJson('/api/auth/password/verify-otp', ['email' => 'reset@test.com', 'otp_code' => $otp])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['reset_token', 'expires_in']);
    }

    public function test_verify_otp_fails_with_wrong_code(): void
    {
        $this->postJson('/api/auth/password/forgot', ['email' => 'reset@test.com']);

        $this->postJson('/api/auth/password/verify-otp', ['email' => 'reset@test.com', 'otp_code' => '000000'])
            ->assertStatus(400);
    }

    public function test_verify_otp_fails_with_missing_fields(): void
    {
        $this->postJson('/api/auth/password/verify-otp', [])->assertStatus(422);
    }

    public function test_verify_otp_fails_for_unknown_email(): void
    {
        $this->postJson('/api/auth/password/verify-otp', ['email' => 'nobody@test.com', 'otp_code' => '123456'])
            ->assertStatus(422);
    }

    // ─── Step 3: reset ──────────────────────────────────────────────────
    public function test_user_can_reset_password_with_valid_reset_token(): void
    {
        $this->postJson('/api/auth/password/reset', [
            'reset_token' => $this->getValidResetToken(),
            'password' => 'new_password123',
            'password_confirmation' => 'new_password123',
        ])->assertStatus(200)->assertJsonPath('success', true);
    }

    public function test_password_is_actually_changed_after_reset(): void
    {
        $this->postJson('/api/auth/password/reset', [
            'reset_token' => $this->getValidResetToken(),
            'password' => 'brand_new_pass123',
            'password_confirmation' => 'brand_new_pass123',
        ]);

        $this->user->refresh();
        $this->assertTrue(Hash::check('brand_new_pass123', $this->user->password));
    }

    public function test_old_password_no_longer_works_after_reset(): void
    {
        $this->postJson('/api/auth/password/reset', [
            'reset_token' => $this->getValidResetToken(),
            'password' => 'new_password123',
            'password_confirmation' => 'new_password123',
        ]);

        $this->user->refresh();
        $this->assertFalse(Hash::check('old_password', $this->user->password));
    }

    public function test_reset_fails_with_missing_reset_token(): void
    {
        $this->postJson('/api/auth/password/reset', [
            'password' => 'new_password123', 'password_confirmation' => 'new_password123',
        ])->assertStatus(422);
    }

    public function test_reset_fails_when_reset_token_is_not_a_uuid(): void
    {
        $this->postJson('/api/auth/password/reset', [
            'reset_token' => 'not-a-uuid',
            'password' => 'new_password123', 'password_confirmation' => 'new_password123',
        ])->assertStatus(422);
    }

    public function test_reset_fails_with_password_mismatch(): void
    {
        $this->postJson('/api/auth/password/reset', [
            'reset_token' => $this->getValidResetToken(),
            'password' => 'new_password123', 'password_confirmation' => 'different_password',
        ])->assertStatus(422);
    }

    public function test_reset_fails_with_password_too_short(): void
    {
        $this->postJson('/api/auth/password/reset', [
            'reset_token' => $this->getValidResetToken(),
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertStatus(422);
    }

    public function test_reset_fails_with_expired_or_unknown_reset_token(): void
    {
        $this->postJson('/api/auth/password/reset', [
            'reset_token' => (string) Str::uuid(),
            'password' => 'new_password123', 'password_confirmation' => 'new_password123',
        ])->assertStatus(400);
    }

    public function test_reset_token_can_only_be_used_once(): void
    {
        $token = $this->getValidResetToken();

        $this->postJson('/api/auth/password/reset', [
            'reset_token' => $token, 'password' => 'new_password123', 'password_confirmation' => 'new_password123',
        ])->assertStatus(200);

        $this->postJson('/api/auth/password/reset', [
            'reset_token' => $token, 'password' => 'another_password123', 'password_confirmation' => 'another_password123',
        ])->assertStatus(400);
    }

    // ─── Helper ─────────────────────────────────────────────────────────
    private function getValidResetToken(): string
    {
        $otp = $this->postJson('/api/auth/password/forgot', ['email' => 'reset@test.com'])->json('otp_code');

        return $this->postJson('/api/auth/password/verify-otp', [
            'email' => 'reset@test.com', 'otp_code' => $otp,
        ])->json('reset_token');
    }
}
