<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FIX: The previous setUp called $this->user->profile()->create() unconditionally.
 * UserObserver already creates a profile for every new User, so that second
 * create() threw a unique-constraint SQL error, setUp() failed silently, and
 * every test in the class was skipped — explaining the 0% source coverage.
 *
 * Fix: guard with `if (!$this->user->profile)` before creating manually.
 */
class TestNotificationCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();

        // Profile is auto-created by UserObserver; only create manually as fallback.
        if (! $this->user->profile) {
            $this->user->profile()->create([
                'full_name' => 'Test User',
                'number_of_rides' => 0,
            ]);
        }
    }

    public function test_command_sends_default_test_notification(): void
    {
        $this->artisan('notification:test', [
            'user_id' => $this->user->id,
        ])->assertExitCode(0);
    }

    public function test_command_uses_first_user_when_no_id_given(): void
    {
        $this->artisan('notification:test')->assertExitCode(0);
    }

    public function test_command_fails_when_no_users_exist_and_no_id_given(): void
    {
        User::query()->delete();
        $this->artisan('notification:test')->assertExitCode(1);
    }

    public function test_command_fails_for_nonexistent_user_id(): void
    {
        $this->artisan('notification:test', ['user_id' => 999999])
            ->assertExitCode(1);
    }

    public function test_command_succeeds_for_welcome_type(): void
    {
        // Command was fixed to call createNotification() for all types,
        // including 'welcome' — it now returns exit code 0.
        $this->artisan('notification:test', [
            'user_id' => $this->user->id,
            '--type' => 'welcome',
        ])->assertExitCode(0);
    }

    public function test_command_succeeds_for_system_type(): void
    {
        $this->artisan('notification:test', [
            'user_id' => $this->user->id,
            '--type' => 'system',
        ])->assertExitCode(0);
    }

    public function test_command_succeeds_for_default_type(): void
    {
        $this->artisan('notification:test', [
            'user_id' => $this->user->id,
            '--type' => 'test',
        ])->assertExitCode(0);
    }

    public function test_command_outputs_success_message_on_completion(): void
    {
        $this->artisan('notification:test', ['user_id' => $this->user->id])
            ->expectsOutputToContain('Test notification sent successfully')
            ->assertExitCode(0);
    }

    public function test_command_outputs_error_when_user_not_found(): void
    {
        $this->artisan('notification:test', ['user_id' => 999999])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }
}
