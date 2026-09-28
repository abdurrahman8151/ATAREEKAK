<?php

namespace Tests\Feature\Console;

use App\Enums\StaffRole;
use App\Models\Employee;
use App\Models\StaffRefreshToken;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanupExpiredStaffTokensCommandTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::create([
            'username' => 'test_staff',
            'email' => 'staff@test.com',
            'password' => bcrypt('password123'),
            'first_name' => 'Test',
            'last_name' => 'Staff',
            'role' => StaffRole::SUPPORT_AGENT->value,
            'is_active' => true,
            'token_version' => 0,
        ]);
    }

    public function test_command_exits_zero_with_no_tokens(): void
    {
        $this->artisan('staff-tokens:cleanup')->assertExitCode(0);
    }

    public function test_command_outputs_cleaning_info(): void
    {
        $this->artisan('staff-tokens:cleanup')
            ->expectsOutputToContain('Cleaning up expired staff tokens')
            ->assertExitCode(0);
    }

    public function test_command_outputs_cleanup_message(): void
    {
        $this->artisan('staff-tokens:cleanup')
            ->expectsOutputToContain('expired/revoked staff tokens')
            ->assertExitCode(0);
    }

    public function test_command_outputs_zero_when_nothing_to_clean(): void
    {
        $this->artisan('staff-tokens:cleanup')
            ->expectsOutputToContain('0')
            ->assertExitCode(0);
    }

    public function test_command_deletes_expired_tokens(): void
    {
        StaffRefreshToken::create([
            'employee_id' => $this->employee->id,
            'token' => hash('sha256', 'expired_staff_token'),
            'expires_at' => Carbon::now()->subDays(2),
            'revoked' => false,
        ]);

        $this->artisan('staff-tokens:cleanup')->assertExitCode(0);

        $this->assertEquals(0, StaffRefreshToken::where('employee_id', $this->employee->id)->count());
    }

    public function test_command_deletes_revoked_tokens(): void
    {
        StaffRefreshToken::create([
            'employee_id' => $this->employee->id,
            'token' => hash('sha256', 'revoked_staff_token'),
            'expires_at' => Carbon::now()->addDay(), // not expired but revoked
            'revoked' => true,
        ]);

        $this->artisan('staff-tokens:cleanup')->assertExitCode(0);

        $this->assertEquals(0, StaffRefreshToken::count());
    }

    public function test_command_preserves_active_valid_tokens(): void
    {
        StaffRefreshToken::create([
            'employee_id' => $this->employee->id,
            'token' => hash('sha256', 'active_staff_token'),
            'expires_at' => Carbon::now()->addDays(7),
            'revoked' => false,
        ]);

        $this->artisan('staff-tokens:cleanup')->assertExitCode(0);

        $this->assertEquals(1, StaffRefreshToken::count());
    }

    public function test_command_deletes_expired_and_revoked_keeps_active(): void
    {
        // Expired
        StaffRefreshToken::create([
            'employee_id' => $this->employee->id,
            'token' => hash('sha256', 'expired'),
            'expires_at' => Carbon::now()->subDays(2),
            'revoked' => false,
        ]);

        // Revoked
        StaffRefreshToken::create([
            'employee_id' => $this->employee->id,
            'token' => hash('sha256', 'revoked'),
            'expires_at' => Carbon::now()->addDay(),
            'revoked' => true,
        ]);

        // Active
        StaffRefreshToken::create([
            'employee_id' => $this->employee->id,
            'token' => hash('sha256', 'active'),
            'expires_at' => Carbon::now()->addDays(7),
            'revoked' => false,
        ]);

        $this->artisan('staff-tokens:cleanup')->assertExitCode(0);

        $this->assertEquals(1, StaffRefreshToken::count());
        $this->assertDatabaseHas('staff_refresh_tokens', ['token' => hash('sha256', 'active')]);
    }

    public function test_command_outputs_deleted_count(): void
    {
        StaffRefreshToken::create([
            'employee_id' => $this->employee->id,
            'token' => hash('sha256', 'expired_1'),
            'expires_at' => Carbon::now()->subDay(),
            'revoked' => false,
        ]);

        $this->artisan('staff-tokens:cleanup')
            ->expectsOutputToContain('1')
            ->assertExitCode(0);
    }
}
