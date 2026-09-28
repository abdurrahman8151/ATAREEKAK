<?php

namespace Tests\Feature\T3Batch;

use Database\Seeders\ResolvesSeedCredentials;
use Tests\TestCase;

/**
 * T3-9 — no committed working passwords in seeders or load-test scripts.
 *
 * Asserted structurally (source text) because these are files, not runtime
 * behaviour — plus one behavioural test for the trait itself, which IS code:
 * env override, per-process memoisation, random fallback shape.
 */
class SeedCredentialsBatchTest extends TestCase
{
    /** @return array<string,string> path => contents */
    private function sources(array $paths): array
    {
        $out = [];
        foreach ($paths as $rel) {
            $out[$rel] = (string) file_get_contents(base_path($rel));
        }

        return $out;
    }

    private function seederFiles(): array
    {
        return [
            'database/seeders/PassengerSeeder.php',
            'database/seeders/DriverSeeder.php',
            'database/seeders/Syrideseeder.php',
            'database/seeders/Atarikaktestseeder.php',
            'database/seeders/UserRealFlowSeeder.php',
            'database/seeders/ResolvesSeedCredentials.php',
        ];
    }

    private function k6Files(): array
    {
        return [
            'k6-load/Bench 500.js',
            'k6-load/Scenario1 no cache no lb.js',
            'k6-load/Scenario2 cache no lb.js',
            'k6-load/Scenario3 lb no cache.js',   // renamed in T4-6 (was ' Scenario3 …')
            'k6-load/Scenario4 cache and lb.js',
        ];
    }

    public function test_no_seeder_hashes_a_committed_password_literal(): void
    {
        // 'password' was the literal in Passenger/DriverSeeder pre-T3-9 — it
        // must be in the list, or reintroducing it would pass this check.
        $literals = ['password', 'password123', 'Password@123', 'Admin1@2024', 'Agent1@2024', 'SystemAdmin2024', 'SyCash2024'];

        foreach ($this->sources($this->seederFiles()) as $rel => $src) {
            foreach ($literals as $lit) {
                // match it only in a Hash::make(...) / quoted-password position
                $this->assertFalse(
                    (bool) preg_match("/Hash::make\(\s*'[^']*".preg_quote($lit, '/')."[^']*'\s*\)/", $src),
                    "$rel still hashes the committed literal '$lit'"
                );
            }
        }
    }

    public function test_every_test_seeder_uses_the_shared_credential_resolver(): void
    {
        foreach ($this->seederFiles() as $rel) {
            if (str_contains($rel, 'ResolvesSeedCredentials')) {
                continue;
            }
            $src = (string) file_get_contents(base_path($rel));
            $this->assertStringContainsString(
                'use ResolvesSeedCredentials;',
                $src,
                "$rel must pull passwords through the trait (T3-9)"
            );
        }
    }

    public function test_k6_scripts_read_credentials_from_the_environment(): void
    {
        foreach ($this->sources($this->k6Files()) as $rel => $src) {
            $this->assertStringNotContainsString('primary@admin.com', $src, "$rel still embeds the admin identifier");
            $this->assertStringNotContainsString("password: 'admin'", $src, "$rel still embeds the admin password");
            $this->assertStringNotContainsString('password: "admin"', $src);
            $this->assertStringContainsString('__ENV.K6_ADMIN_EMAIL', $src, "$rel must use --env K6_ADMIN_EMAIL");
            $this->assertStringContainsString('__ENV.K6_ADMIN_PASSWORD', $src);
        }
    }

    public function test_k6_scripts_fail_fast_without_env_credentials(): void
    {
        foreach ($this->sources($this->k6Files()) as $rel => $src) {
            $this->assertMatchesRegularExpression(
                '/if\s*\(!__ENV\.K6_ADMIN_EMAIL\s*\|\|\s*!__ENV\.K6_ADMIN_PASSWORD\)/',
                $src,
                "$rel must refuse to run when the env vars are missing"
            );
        }
    }

    // ── the trait itself (real behaviour) ───────────────────────────────────
    public function test_the_trait_prefers_the_env_value(): void
    {
        putenv('T39_TEST_PW=from-env-value');
        $resolver = $this->resolver();

        $this->assertSame('from-env-value', $resolver('T39_TEST_PW'));
    }

    public function test_the_trait_generates_one_stable_random_value_per_key(): void
    {
        putenv('T39_TEST_PW'); // unset
        $resolver = $this->resolver();

        $a = $resolver('T39_UNSET_KEY_'.uniqid());
        $b = $resolver('T39_UNSET_KEY_SAME');
        // two calls with the SAME key must memoise to the same value:
        $k = 'T39_MEMO_'.uniqid();
        $this->assertSame($resolver($k), $resolver($k), 'per-process memoisation');
        $this->assertNotSame($a, $b, 'different keys must not share a value');
        $this->assertMatchesRegularExpression('/.{16}#\d{2}$/', $a, 'generated shape: 16 random chars + #NN');
    }

    public function test_the_generated_password_never_equals_a_known_literal(): void
    {
        putenv('T39_TEST_PW');
        $pw = $this->resolver()('T39_RANDOM_'.uniqid());
        $this->assertFalse(in_array($pw, ['password', 'password123', 'Password@123'], true));
    }

    /** @return callable(string):string */
    private function resolver(): callable
    {
        $seed = new class
        {
            use ResolvesSeedCredentials;

            public function make(string $key): string
            {
                return $this->seedPassword($key);
            }
        };

        return fn (string $k): string => $seed->make($k);
    }
}
