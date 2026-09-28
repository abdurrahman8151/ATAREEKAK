<?php

namespace Tests\Feature\Console;

use App\Models\Otp;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanupExpiredOtpsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_exits_zero_with_no_otps(): void
    {
        $this->artisan('otp:cleanup')->assertExitCode(0);
    }

    public function test_command_outputs_cleaned_count_message(): void
    {
        $this->artisan('otp:cleanup')
            ->expectsOutputToContain('Cleaned up')
            ->assertExitCode(0);
    }

    public function test_command_outputs_zero_when_nothing_to_clean(): void
    {
        $this->artisan('otp:cleanup')
            ->expectsOutputToContain('0')
            ->assertExitCode(0);
    }

    public function test_command_deletes_expired_otps(): void
    {
        Otp::create([
            'phone_number' => '+963911111111',
            'otp_code' => '111111',
            'type' => 'E-PAYMENT',
            'expires_at' => Carbon::now()->subHours(2),
            'is_verified' => false,
            'attempts' => 0,
        ]);

        Otp::create([
            'phone_number' => '+963922222222',
            'otp_code' => '222222',
            'type' => 'E-PAYMENT',
            'expires_at' => Carbon::now()->subMinutes(10),
            'is_verified' => false,
            'attempts' => 0,
        ]);

        $this->artisan('otp:cleanup')->assertExitCode(0);

        $this->assertDatabaseMissing('otps', ['phone_number' => '+963911111111']);
        $this->assertDatabaseMissing('otps', ['phone_number' => '+963922222222']);
    }

    public function test_command_preserves_active_otps(): void
    {
        // Active OTP — should NOT be deleted
        Otp::create([
            'phone_number' => '+963933333333',
            'otp_code' => '333333',
            'type' => 'E-PAYMENT',
            'expires_at' => Carbon::now()->addMinutes(5),
            'is_verified' => false,
            'attempts' => 0,
        ]);

        $this->artisan('otp:cleanup')->assertExitCode(0);

        $this->assertDatabaseHas('otps', ['phone_number' => '+963933333333']);
    }

    public function test_command_deletes_expired_but_keeps_active(): void
    {
        Otp::create([
            'phone_number' => '+963911111111',
            'otp_code' => '111111',
            'type' => 'E-PAYMENT',
            'expires_at' => Carbon::now()->subHour(),
            'is_verified' => false,
            'attempts' => 0,
        ]);
        Otp::create([
            'phone_number' => '+963922222222',
            'otp_code' => '222222',
            'type' => 'E-PAYMENT',
            'expires_at' => Carbon::now()->addHour(),
            'is_verified' => false,
            'attempts' => 0,
        ]);

        $this->artisan('otp:cleanup')->assertExitCode(0);

        $this->assertDatabaseMissing('otps', ['phone_number' => '+963911111111']);
        $this->assertDatabaseHas('otps', ['phone_number' => '+963922222222']);
    }

    public function test_command_reports_correct_deleted_count(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            Otp::create([
                'phone_number' => "+96391111111{$i}",
                'otp_code' => str_pad($i, 6, '0', STR_PAD_LEFT),
                'type' => 'E-PAYMENT',
                'expires_at' => Carbon::now()->subHours($i),
                'is_verified' => false,
                'attempts' => 0,
            ]);
        }

        $this->artisan('otp:cleanup')
            ->expectsOutputToContain('3')
            ->assertExitCode(0);
    }
}
