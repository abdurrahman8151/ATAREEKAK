<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\VerifyEmailOtpRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Unit tests for VerifyEmailOtpRequest.
 *
 * Validates the ->rules() array directly against sample payloads
 * via Validator::make(), rather than dispatching a full HTTP request.
 * RefreshDatabase is required because the 'exists:users,email' rule
 * queries the database.
 */
class VerifyEmailOtpRequestTest extends TestCase
{
    use RefreshDatabase;

    private function rules(): array
    {
        return (new VerifyEmailOtpRequest)->rules();
    }

    public function test_authorize_always_returns_true(): void
    {
        $this->assertTrue((new VerifyEmailOtpRequest)->authorize());
    }

    // ─── Valid payload ────────────────────────────────────────────────────────────

    public function test_passes_with_registered_email_and_six_digit_otp(): void
    {
        User::factory()->create(['email' => 'registered@example.com']);

        $validator = Validator::make([
            'email' => 'registered@example.com',
            'otp_code' => '123456',
        ], $this->rules());

        $this->assertTrue($validator->passes(), $validator->errors()->__toString());
    }

    public function test_passes_with_all_zeros_as_otp(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $validator = Validator::make([
            'email' => 'test@example.com',
            'otp_code' => '000000',
        ], $this->rules());

        $this->assertTrue($validator->passes(), $validator->errors()->__toString());
    }

    // ─── Email failures ───────────────────────────────────────────────────────────

    public function test_fails_when_email_is_missing(): void
    {
        $validator = Validator::make(['otp_code' => '123456'], $this->rules());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());
    }

    public function test_fails_when_email_has_invalid_format(): void
    {
        $validator = Validator::make([
            'email' => 'not-an-email',
            'otp_code' => '123456',
        ], $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());
    }

    public function test_fails_when_email_is_not_registered(): void
    {
        // No user created — exists:users,email should fail
        $validator = Validator::make([
            'email' => 'nobody@example.com',
            'otp_code' => '123456',
        ], $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());
    }

    // ─── OTP code failures ────────────────────────────────────────────────────────

    public function test_fails_when_otp_code_is_missing(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $validator = Validator::make(['email' => 'test@example.com'], $this->rules());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('otp_code', $validator->errors()->toArray());
    }

    public function test_fails_when_otp_code_is_five_digits(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $validator = Validator::make([
            'email' => 'test@example.com',
            'otp_code' => '12345',
        ], $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('otp_code', $validator->errors()->toArray());
    }

    public function test_fails_when_otp_code_is_seven_digits(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $validator = Validator::make([
            'email' => 'test@example.com',
            'otp_code' => '1234567',
        ], $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('otp_code', $validator->errors()->toArray());
    }

    public function test_fails_when_otp_code_contains_a_letter(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $validator = Validator::make([
            'email' => 'test@example.com',
            'otp_code' => '12345a',
        ], $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('otp_code', $validator->errors()->toArray());
    }

    public function test_fails_when_otp_code_is_empty_string(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $validator = Validator::make([
            'email' => 'test@example.com',
            'otp_code' => '',
        ], $this->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('otp_code', $validator->errors()->toArray());
    }

    public function test_fails_when_both_fields_are_missing(): void
    {
        $validator = Validator::make([], $this->rules());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email', $validator->errors()->toArray());
        $this->assertArrayHasKey('otp_code', $validator->errors()->toArray());
    }

    // ─── Custom messages ──────────────────────────────────────────────────────────

    public function test_custom_messages_cover_all_expected_keys(): void
    {
        $messages = (new VerifyEmailOtpRequest)->messages();

        $this->assertArrayHasKey('email.exists', $messages);
        $this->assertArrayHasKey('otp_code.size', $messages);
        $this->assertArrayHasKey('otp_code.regex', $messages);
    }

    public function test_email_exists_message_text(): void
    {
        $messages = (new VerifyEmailOtpRequest)->messages();
        $this->assertEquals('No account found with this email.', $messages['email.exists']);
    }

    public function test_otp_size_message_text(): void
    {
        $messages = (new VerifyEmailOtpRequest)->messages();
        $this->assertEquals('Code must be exactly 6 digits.', $messages['otp_code.size']);
    }

    public function test_otp_regex_message_text(): void
    {
        $messages = (new VerifyEmailOtpRequest)->messages();
        $this->assertEquals('Code must contain numbers only.', $messages['otp_code.regex']);
    }
}
