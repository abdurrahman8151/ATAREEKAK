<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\SendEmailOtpRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * SendEmailOtpRequestTest – Unit tests for SendEmailOtpRequest.
 *
 * Tests ->rules() directly via Validator::make() – no HTTP request dispatched.
 *
 * RULES:
 *   email  required|string|email|max:255
 *   type   sometimes|in:EMAIL_VERIFICATION,PASSWORD_RESET
 */
class SendEmailOtpRequestTest extends TestCase
{
    private function rules(): array
    {
        return (new SendEmailOtpRequest)->rules();
    }

    private function valid(array $overrides = []): array
    {
        return array_merge(['email' => 'user@example.com'], $overrides);
    }

    // ─── authorize ────────────────────────────────────────────────────────────

    public function test_authorize_always_returns_true(): void
    {
        $this->assertTrue((new SendEmailOtpRequest)->authorize());
    }

    // ─── email – required|string|email|max:255 ────────────────────────────────

    public function test_passes_with_valid_email(): void
    {
        $v = Validator::make($this->valid(), $this->rules());
        $this->assertTrue($v->passes(), $v->errors()->__toString());
    }

    public function test_fails_when_email_is_missing(): void
    {
        $v = Validator::make([], $this->rules());
        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('email', $v->errors()->toArray());
    }

    public function test_fails_when_email_is_not_valid_format(): void
    {
        $v = Validator::make($this->valid(['email' => 'not-an-email']), $this->rules());
        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('email', $v->errors()->toArray());
    }

    public function test_fails_when_email_exceeds_255_characters(): void
    {
        // FIX: the previous value was str_repeat('a', 248) . '@b.co'
        // which is 248 + 5 = 253 characters — below the max:255 threshold,
        // so the validator passed and the test failed.
        // 251 + '@b.co' (5) = 256 characters, which exceeds max:255.
        $v = Validator::make(
            $this->valid(['email' => str_repeat('a', 251).'@b.co']),
            $this->rules()
        );
        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('email', $v->errors()->toArray());
    }

    public function test_fails_when_email_is_empty_string(): void
    {
        $v = Validator::make($this->valid(['email' => '']), $this->rules());
        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('email', $v->errors()->toArray());
    }

    public function test_fails_when_email_has_no_domain_tld(): void
    {
        // FIX: Laravel's `email` rule (filter_var / egulias RFCValidation) does
        // NOT enforce TLD requirements — 'user@nodot' is treated as a valid
        // single-label domain and passes. Use 'user@' instead: a domain
        // component that is entirely absent is rejected by every validator
        // implementation, making this test reliable without changing the rule.
        $v = Validator::make($this->valid(['email' => 'user@']), $this->rules());
        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('email', $v->errors()->toArray());
    }

    // ─── type – sometimes|in:EMAIL_VERIFICATION,PASSWORD_RESET ───────────────

    public function test_passes_without_type_field(): void
    {
        $v = Validator::make($this->valid(), $this->rules());
        $this->assertTrue($v->passes());
    }

    public function test_passes_with_email_verification_type(): void
    {
        $v = Validator::make($this->valid(['type' => 'EMAIL_VERIFICATION']), $this->rules());
        $this->assertTrue($v->passes(), $v->errors()->__toString());
    }

    public function test_passes_with_password_reset_type(): void
    {
        $v = Validator::make($this->valid(['type' => 'PASSWORD_RESET']), $this->rules());
        $this->assertTrue($v->passes(), $v->errors()->__toString());
    }

    public function test_fails_with_invalid_type_value(): void
    {
        $v = Validator::make($this->valid(['type' => 'INVALID_TYPE']), $this->rules());
        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('type', $v->errors()->toArray());
    }

    public function test_fails_with_lowercase_type_value(): void
    {
        // Rules are case-sensitive – 'email_verification' is not in the allowed list
        $v = Validator::make($this->valid(['type' => 'email_verification']), $this->rules());
        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('type', $v->errors()->toArray());
    }

    // ─── messages ─────────────────────────────────────────────────────────────

    public function test_messages_returns_array(): void
    {
        $this->assertIsArray((new SendEmailOtpRequest)->messages());
    }

    public function test_messages_contains_email_required_key(): void
    {
        $this->assertArrayHasKey('email.required', (new SendEmailOtpRequest)->messages());
    }

    public function test_messages_contains_email_format_key(): void
    {
        $this->assertArrayHasKey('email.email', (new SendEmailOtpRequest)->messages());
    }

    public function test_email_required_message_is_not_empty(): void
    {
        $messages = (new SendEmailOtpRequest)->messages();
        $this->assertNotEmpty($messages['email.required']);
    }
}
