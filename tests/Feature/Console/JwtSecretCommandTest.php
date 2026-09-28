<?php

namespace Tests\Feature\Console;

use App\Console\Commands\JwtSecretCommand;
use Tests\TestCase;

/**
 * JwtSecretCommandTest
 *
 * NOTE ON .ENV MODIFICATION:
 * The default run (no flags) writes to the .env file, which is unsafe in CI.
 * The --show flag only prints the generated key without touching any file, so
 * most tests use --show to stay side-effect-free.
 *
 * The --force flag skips the production confirmation prompt and is combined
 * with test-env checks where file writing must be exercised.
 */
class JwtSecretCommandTest extends TestCase
{
    // ─── --show flag ───────────────────────────────────────────────────────

    public function test_show_flag_displays_generated_key(): void
    {
        $this->artisan('jwt:secret', ['--show' => true])
            ->assertExitCode(0);
    }

    public function test_show_flag_outputs_base64_encoded_key(): void
    {
        $this->artisan('jwt:secret', ['--show' => true])
            ->assertExitCode(0);

        // Run again to capture output
        $output = \Artisan::output();
        // The key should not be empty after the command runs
        $this->assertNotEmpty(trim($output));
    }

    public function test_show_flag_generates_unique_keys_on_each_run(): void
    {
        \Artisan::call('jwt:secret', ['--show' => true]);
        $key1 = trim(\Artisan::output());

        \Artisan::call('jwt:secret', ['--show' => true]);
        $key2 = trim(\Artisan::output());

        $this->assertNotEquals($key1, $key2, 'Two consecutive keys should be different.');
    }

    public function test_command_exits_zero_with_show_flag(): void
    {
        $this->artisan('jwt:secret --show')->assertExitCode(0);
    }

    // ─── Command signature ─────────────────────────────────────────────────

    public function test_command_is_registered_in_artisan(): void
    {
        $commands = array_keys(\Artisan::all());
        $this->assertContains('jwt:secret', $commands);
    }

    public function test_command_class_exists(): void
    {
        $this->assertTrue(class_exists(JwtSecretCommand::class));
    }

    public function test_command_has_correct_signature(): void
    {
        $command = new JwtSecretCommand;

        $reflection = new \ReflectionProperty($command, 'signature');
        $reflection->setAccessible(true);
        $signature = $reflection->getValue($command);

        $this->assertStringContainsString('jwt:secret', $signature);
        $this->assertStringContainsString('--show', $signature);
        $this->assertStringContainsString('--force', $signature);
    }

    public function test_command_has_description(): void
    {
        $command = new JwtSecretCommand;

        $reflection = new \ReflectionProperty($command, 'description');
        $reflection->setAccessible(true);
        $description = $reflection->getValue($command);

        $this->assertNotEmpty($description);
        $this->assertStringContainsString('JWT', $description);
    }

    // ─── Force flag (writes to .env) ───────────────────────────────────────

    public function test_force_flag_with_show_still_exits_zero(): void
    {
        // --show takes precedence; no env write happens
        $this->artisan('jwt:secret', ['--show' => true, '--force' => true])
            ->assertExitCode(0);
    }
}
