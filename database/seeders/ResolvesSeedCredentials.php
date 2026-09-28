<?php

namespace Database\Seeders;

use Illuminate\Support\Str;

/**
 * T3-9 — single source of truth for *test* account passwords.
 *
 * These seeders used to hardcode working credentials into tracked files
 * (a known literal for drivers/passengers, a known literal for the main
 * test accounts, and per-index literals like Admin{i}@<year> for staff)
 * and one even printed them, so any deployable artifact contained a
 * usable password and anyone reading the repo knew the account format.
 *
 * This trait keeps the developer workflow intact — every seeded account still
 * shares one known password, still printed at the end of the run so you can log
 * in — while the value itself never appears in version control:
 *
 *   1. If the env variable is set, it is used (CI / team-wide determinism).
 *   2. Otherwise a random value is generated ONCE per process and reused, so
 *      login still works within the seeded environment; it is reported by
 *      reportPassword() rather than guessed from the source.
 *
 * Random-by-default is the behaviour the original audit asked for ("Generate
 * random passwords and print/require them, or read from env as
 * SpecialAccountSeeder does"). It deliberately does not fall back to a literal.
 */
trait ResolvesSeedCredentials
{
    /** @var array<string,string> memoised per process */
    private static array $resolvedSeedPasswords = [];

    protected function seedPassword(string $envKey): string
    {
        $fromEnv = env($envKey);

        if (is_string($fromEnv) && $fromEnv !== '') {
            return self::$resolvedSeedPasswords[$envKey] ??= $fromEnv;
        }

        return self::$resolvedSeedPasswords[$envKey]
            ??= Str::random(16) . '#' . random_int(10, 99);
    }

    /**
     * Tell the operator what the generated password was. Called from the seeder
     * summary so the value is discoverable at run time without being committed.
     */
    protected function reportPassword(string $label, string $envKey): void
    {
        if (! isset($this->command)) {
            return;
        }

        $fromEnv = env($envKey);
        $source  = (is_string($fromEnv) && $fromEnv !== '') ? "from {$envKey}" : 'generated for this run';

        $this->command->line("  {$label}: {$this->seedPassword($envKey)}  ({$source})");
    }
}
