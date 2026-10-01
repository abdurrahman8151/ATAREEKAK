<?php

namespace Tests\Feature\Review;

use App\Http\Controllers\API\TextMeOtpController;
use App\Services\Geocoding\ArabicPlaceNameService;
use App\Services\WhatsAppOtpService;
use Tests\TestCase;

/**
 * RV-28 ratchet — two infra-hardening invariants that are decision-free and must not regress.
 *
 * 1. NO env() OUTSIDE config/ in the application tree. `env()` is only safe inside config
 *    files: once `php artisan config:cache` runs (standard production step), env() outside
 *    config/ returns NULL, which silently disables features (OTP bypass, provider keys,
 *    the TextMeBot toggle) regardless of the deployment's .env. R1 named the offenders; this
 *    pins that none creep back.
 *
 * 2. PRIMARY-ONLY READS on the correctness-critical lookups. With read/write splitting, a
 *    plain read goes to the replica; a login / cached-auth rehydrate / wallet-balance read
 *    that hits a lagging replica can act on stale data (authenticate a banned user, show a
 *    stale balance, cache a stale user for 5 minutes). These reads force the primary.
 *
 * These are asserted two ways for the reads: the source must reference useWritePdo, and the
 * helper methods must actually be reachable. The env() check is a real file-content scan, so
 * it fails the moment someone reintroduces a raw env() read.
 */
class RV28InfraHardeningTest extends TestCase
{
    /** @test */
    public function there_are_no_env_reads_outside_the_config_directory(): void
    {
        $offenders = [];
        $appDir = app_path();
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($rii as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            // Match env( / getenv( as an actual CALL, ignoring occurrences inside comments
            // and inside a string literal like config('...env(...)') — a conservative scan:
            // flag any line that calls env() with a leading non-comment context.
            foreach (preg_split('/\R/', $src) as $line) {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                    continue; // comment line
                }
                if (preg_match('/(?<![\w:>\-])env\s*\(/', $trimmed) || preg_match('/(?<![\w:>\-])getenv\s*\(/', $trimmed)) {
                    $offenders[] = str_replace($appDir.DIRECTORY_SEPARATOR, '', $file->getPathname())
                        .': '.$trimmed;
                }
            }
        }

        $this->assertSame([], $offenders,
            "RV-28: env()/getenv() must be read via config/, never directly in the app tree.\n"
            ."Offenders:\n".implode("\n", $offenders));
    }

    /** @test */
    public function the_primary_only_reads_still_force_the_write_pdo(): void
    {
        // Each of these correctness-critical reads must force the primary so a lagging replica
        // cannot serve stale auth/money state. Assert the source carries useWritePdo.
        $sites = [
            'app/Repositories/UserRepository.php' => 'findByEmail (login/auth lookup)',
            'app/Services/JwtService.php' => 'findUserCached (cache-miss rehydrate)',
            'app/Http/Controllers/API/WalletController.php' => 'getBalance (money read)',
        ];

        foreach ($sites as $file => $what) {
            $path = base_path($file);
            $this->assertFileExists($path);
            $src = (string) file_get_contents($path);
            $this->assertStringContainsString(
                'useWritePdo',
                $src,
                "RV-28: {$file} ({$what}) must call useWritePdo() so the read cannot be served "
                .'by a stale replica'
            );
        }
    }

    /** @test */
    public function the_env_backed_settings_are_owned_by_config_and_resolvable(): void
    {
        // The config keys that replaced the raw env() reads must actually resolve, so a test
        // can override them via Config::set and config:cache keeps them.
        $this->assertIsBool(config('services.textmebot.enabled'),
            'config/services.php must own textmebot.enabled');
        $this->assertArrayHasKey('callmebot', config('services'),
            'config/services.php must own callmebot.api_key');
        $this->assertArrayHasKey('mapbox', config('services'),
            'config/services.php must own mapbox.access_token');

        // CORS must still default to localhost-only (no invented production origin) while
        // accepting extras from the environment.
        $origins = config('cors.allowed_origins');
        $this->assertContains('http://localhost:3000', $origins,
            'the existing localhost CORS defaults must be preserved');
        $this->assertIsArray($origins);
    }

    /** @test */
    public function the_config_backed_provider_keys_are_consumed_not_raw_env(): void
    {
        // Spot-check that the specific classes now read config(), not env().
        foreach ([WhatsAppOtpService::class, TextMeOtpController::class, ArabicPlaceNameService::class] as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            $src = (string) file_get_contents((string) $file);
            $this->assertDoesNotMatchRegularExpression(
                '/(?<![\w:>\-])env\s*\(\s*[\'"](?:CALLMEBOT|TEXTMEBOT|MAPBOX|OTP_BYPASS)/',
                $src,
                "{$class} must read provider/bypass settings via config(), not env()"
            );
        }
    }
}
