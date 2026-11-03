<?php

namespace Tests\Feature\AppFuture;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Stage 1.5 item 3: `app/Console/Testing` is the module-boundary rule's one directory-level exemption,
 * and it is exempt only because `App\Console\Kernel::commands()` registers it outside production. That
 * claim is load-bearing, so it is pinned here from both sides:
 *
 *  - the guard must exist, must test exactly `local` and `testing`, and must be the only place the
 *    directory is loaded (one load call, inside the guard);
 *  - the dev commands must actually be registered in this (testing) environment, so the exemption is
 *    about a directory that is doing real work rather than a directory that quietly stopped loading.
 *
 * What this test does not do is boot a second application with `APP_ENV=production`: mutating the
 * environment of a booted application and re-bootstrapping the console kernel would re-run
 * `routes/console.php` and re-register commands, which is a fragile thing to assert with. The guard's
 * shape is asserted instead, and the audit says so rather than claiming a production boot was run.
 */
class DevCommandRegistrationTest extends TestCase
{
    /** The five commands under the exempt directory, by the name they register. */
    private const DEV_COMMANDS = [
        'loadtest:tokens',
        'syride:test-noshow',
        'syride:test-completion',
        'syride:test-ride-interaction',
        'notification:test',
    ];

    public function test_the_testing_directory_is_loaded_only_inside_the_local_and_testing_guard(): void
    {
        $kernel = (string) file_get_contents(app_path('Console/Kernel.php'));

        $this->assertStringContainsString(
            "if (\$this->app->environment(['local', 'testing'])) {",
            $kernel,
            'The Console/Testing directory is exempt from the module-boundary rule only while this guard exists.'
        );

        $this->assertMatchesRegularExpression(
            "/environment\(\['local', 'testing'\]\)\)\s*\{\s*\\\$this->load\(__DIR__\.'\/Testing'\);/",
            $kernel,
            'The Testing directory must be loaded inside the local/testing guard, not before it.'
        );

        $this->assertSame(
            1,
            substr_count($kernel, "\$this->load(__DIR__.'/Testing');"),
            'The Testing directory must be loaded exactly once; a second, unguarded load would make the exemption a production hole.'
        );

        $this->assertSame(
            1,
            substr_count($kernel, "\$this->app->environment(['local', 'testing'])"),
            'The environment guard must appear exactly once, so it cannot be one of several conditions.'
        );
    }

    public function test_the_exempt_directory_is_not_empty_and_its_commands_are_registered(): void
    {
        $files = glob(app_path('Console/Testing/*.php')) ?: [];
        $support = glob(app_path('Console/Testing/Support/*.php')) ?: [];

        // Floor: 8 files measured 2026-11-03 (6 commands, 2 Support classes). An exemption over a
        // directory that lost its contents would hide nothing and exempt nothing.
        $this->assertGreaterThanOrEqual(
            8,
            count($files) + count($support),
            'The Console/Testing exemption covers only '.(count($files) + count($support)).' files.'
        );

        $registered = array_keys(Artisan::all());

        foreach (self::DEV_COMMANDS as $name) {
            $this->assertContains(
                $name,
                $registered,
                "The dev command {$name} is not registered in the testing environment, so the Console/Testing exemption is covering a directory that no longer does anything."
            );
        }
    }
}
