<?php

namespace Tests\Feature\Console;

use App\Models\RefreshToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanupExpiredTokensCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_exits_zero_with_no_tokens(): void
    {
        $this->artisan('tokens:cleanup')->assertExitCode(0);
    }

    public function test_command_outputs_cleanup_message(): void
    {
        $this->artisan('tokens:cleanup')
            ->expectsOutputToContain('expired/revoked tokens')
            ->assertExitCode(0);
    }

    public function test_command_deletes_expired_tokens(): void
    {
        $user = User::factory()->create();

        RefreshToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', 'expired_token'),
            'expires_at' => Carbon::now()->subDays(2),
            'revoked' => false,
        ]);

        $this->artisan('tokens:cleanup')->assertExitCode(0);

        $this->assertEquals(0, RefreshToken::where('user_id', $user->id)->count());
    }

    public function test_command_deletes_revoked_tokens(): void
    {
        $user = User::factory()->create();

        RefreshToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', 'revoked_token'),
            'expires_at' => Carbon::now()->addDay(), // not expired but revoked
            'revoked' => true,
        ]);

        $this->artisan('tokens:cleanup')->assertExitCode(0);

        $this->assertEquals(0, RefreshToken::count());
    }

    public function test_command_preserves_active_valid_tokens(): void
    {
        $user = User::factory()->create();

        RefreshToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', 'active_token'),
            'expires_at' => Carbon::now()->addDays(7),
            'revoked' => false,
        ]);

        $this->artisan('tokens:cleanup')->assertExitCode(0);

        $this->assertEquals(1, RefreshToken::count());
    }

    public function test_command_deletes_expired_and_revoked_keeps_active(): void
    {
        $user = User::factory()->create();

        // Expired
        RefreshToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', 'expired'),
            'expires_at' => Carbon::now()->subDays(2),
            'revoked' => false,
        ]);
        // Revoked
        RefreshToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', 'revoked'),
            'expires_at' => Carbon::now()->addDay(),
            'revoked' => true,
        ]);
        // Active
        RefreshToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', 'active'),
            'expires_at' => Carbon::now()->addDays(7),
            'revoked' => false,
        ]);

        $this->artisan('tokens:cleanup')->assertExitCode(0);

        $this->assertEquals(1, RefreshToken::count());
        $this->assertDatabaseHas('refresh_tokens', ['token' => hash('sha256', 'active')]);
    }

    public function test_command_outputs_cleaning_info(): void
    {
        $this->artisan('tokens:cleanup')
            ->expectsOutputToContain('Cleaning up expired tokens')
            ->assertExitCode(0);
    }

    public function test_command_outputs_deleted_count(): void
    {
        $user = User::factory()->create();

        RefreshToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', 'expired_1'),
            'expires_at' => Carbon::now()->subDay(),
            'revoked' => false,
        ]);

        $this->artisan('tokens:cleanup')
            ->expectsOutputToContain('1')
            ->assertExitCode(0);
    }
}
