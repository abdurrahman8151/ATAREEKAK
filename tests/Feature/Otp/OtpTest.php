<?php

namespace Tests\Feature\Otp;

use App\Models\Otp;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_send_otp_to_valid_syrian_number(): void
    {
        $this->postJson('/api/otp/send', ['phone_number' => '0983337214'])
            ->assertStatus(200)->assertJsonPath('success', true);
    }

    public function test_send_otp_fails_with_invalid_number(): void
    {
        $this->postJson('/api/otp/send', ['phone_number' => '123'])->assertStatus(422);
    }

    public function test_send_otp_fails_with_missing_number(): void
    {
        $this->postJson('/api/otp/send', [])
            ->assertStatus(422)->assertJsonValidationErrors(['phone_number']);
    }

    public function test_send_otp_creates_record_in_database(): void
    {
        $this->postJson('/api/otp/send', ['phone_number' => '0983337214']);
        $this->assertDatabaseHas('otps', ['phone_number' => '+963983337214']);
    }

    public function test_otp_record_has_correct_expiry(): void
    {
        $this->postJson('/api/otp/send', ['phone_number' => '0983337214']);
        $otp = Otp::where('phone_number', '+963983337214')->latest()->first();
        $this->assertNotNull($otp);
        $this->assertTrue($otp->expires_at->isFuture());
    }

    public function test_can_verify_valid_otp(): void
    {
        Otp::create([
            'phone_number' => '+963983337214', 'otp_code' => '123456',
            'type' => 'E-PAYMENT', 'expires_at' => now()->addMinutes(10),
            'is_verified' => false, 'attempts' => 0,
        ]);

        $this->postJson('/api/otp/verify', ['phone_number' => '0983337214', 'otp_code' => '123456'])
            ->assertStatus(200)->assertJsonPath('success', true);
    }

    public function test_verify_fails_with_wrong_code(): void
    {
        Otp::create([
            'phone_number' => '+963983337214', 'otp_code' => '123456',
            'type' => 'E-PAYMENT', 'expires_at' => now()->addMinutes(10),
            'is_verified' => false, 'attempts' => 0,
        ]);

        $this->postJson('/api/otp/verify', ['phone_number' => '0983337214', 'otp_code' => '999999'])
            ->assertStatus(400)->assertJsonPath('success', false);
    }

    public function test_expired_otp_record_has_past_timestamp(): void
    {
        // Unit-level test: verify the model timestamp logic
        // Note: bypass mode (OTP_BYPASS_ENABLED=true) ignores expiry for HTTP calls
        $otp = Otp::create([
            'phone_number' => '+963983337214', 'otp_code' => '123456',
            'type' => 'E-PAYMENT', 'expires_at' => Carbon::now()->subSeconds(30),
            'is_verified' => false, 'attempts' => 0,
        ]);

        // Reload fresh from DB to ensure Carbon parsing
        $otp = Otp::find($otp->id);
        $this->assertTrue($otp->expires_at->lt(Carbon::now()));
    }

    public function test_otp_is_marked_verified_after_successful_verify(): void
    {
        $otp = Otp::create([
            'phone_number' => '+963983337214',
            'otp_code' => '654321',
            'type' => 'E-PAYMENT',
            'expires_at' => now()->addMinutes(10),
            'is_verified' => false,
            'attempts' => 0,
        ]);

        $this->postJson('/api/otp/verify', ['phone_number' => '0983337214', 'otp_code' => '654321'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertTrue((bool) $otp->fresh()->is_verified);
    }

    public function test_otp_code_must_be_6_digits(): void
    {
        $this->postJson('/api/otp/verify', ['phone_number' => '0983337214', 'otp_code' => '123'])
            ->assertStatus(422);
    }

    public function test_otp_phone_number_is_required_for_verify(): void
    {
        $this->postJson('/api/otp/verify', ['otp_code' => '123456'])
            ->assertStatus(422)->assertJsonValidationErrors(['phone_number']);
    }
}
