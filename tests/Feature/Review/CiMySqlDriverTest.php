<?php

namespace Tests\Feature\Review;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RV-18 — CI must fail loudly when MySQL is required but not actually used.
 *
 * V6 recorded the defect precisely: the committed `phpunit.xml` pins
 * `DB_CONNECTION=sqlite` / `:memory:` with no `force`, the CI workflows provision a real
 * MySQL service but write `DB_CONNECTION=mysql` only into `.env`, and Laravel's Dotenv
 * repository is IMMUTABLE — it does not overwrite a value phpunit.xml already putenv()'d.
 * So CI ran on sqlite, and because every money/geo/lock suite is gated by
 *
 *     if (env('DB_CONNECTION', 'sqlite') !== 'mysql') { markTestSkipped(...); }
 *
 * those suites SKIPPED in CI while the job reported green. A green pipeline that had
 * never executed one money path is worse than a red one: it hides regressions exactly
 * where the money lives.
 *
 * This test is the alarm. It is inert during normal local development (the variable is
 * unset), and under `CI_REQUIRE_MYSQL=1` — which the workflows set — it refuses to let a
 * sqlite connection pass silently, and refuses to let a money/geo suite skip.
 *
 * The fix itself is the job-level `env: DB_CONNECTION=mysql` in the workflows (process
 * env beats the non-forced sqlite pin, which is the only layer that can win).
 * `phpunit.mysql.xml` is a config that omits the pin, useful for local MySQL runs
 * (`vendor/bin/phpunit -c phpunit.mysql.xml`).
 */
class CiMySqlDriverTest extends TestCase
{
    /**
     * Suites whose whole purpose is money or spatial correctness. If the driver is not
     * mysql they skip, which is the silent-failure V6 is about. Named explicitly rather
     * than discovered, so adding a money suite means adding it here.
     */
    private const MONEY_OR_GEO_SUITES = [
        'AdminFinancialReportEscrowTest',
        'UntangleBatchTest',
        'RideChannelAuthorizationTest',
        'CancelSeatsEquivalenceCheck',
        'NoshowSettlementGuardTest',
        'MoneyPathBatchTest',
        'MoneyAndAuthPathBatchTest',
        'WaveZeroVerificationTest',
    ];

    /** @test */
    public function when_ci_requires_mysql_the_connection_really_is_mysql(): void
    {
        if (! $this->ciRequiresMysql()) {
            // Not the CI signal run: nothing to assert. The alarm is only meaningful
            // where the workflow declares MySQL mandatory.
            $this->markTestSkippedNotRequired();

            return;
        }

        $driver = DB::connection()->getDriverName();
        $this->assertSame(
            'mysql',
            $driver,
            "RV-18/V6: CI_REQUIRE_MYSQL=1 but the tests are running on '{$driver}'. "
            .'This is the silent-skip leak: the committed phpunit.xml pins '
            .'DB_CONNECTION=sqlite with no force, and neither .env (Dotenv is immutable) '
            .'nor a late putenv can win against a value already set -- so the fix must be '
            .'at the PROCESS level: the CI workflows set DB_CONNECTION=mysql in the job '
            .'env:, and locally run vendor/bin/phpunit -c phpunit.mysql.xml, which does '
            .'not pin the driver.'
        );
    }

    /** @test */
    public function when_ci_requires_mysql_no_money_or_geo_suite_may_skip(): void
    {
        if (! $this->ciRequiresMysql()) {
            $this->markTestSkippedNotRequired();

            return;
        }

        // The guard every money/geo suite uses. If it would fire, those suites report
        // green while executing nothing — the exact failure mode V6 describes.
        $connection = env('DB_CONNECTION', 'sqlite');
        $this->assertSame(
            'mysql',
            $connection,
            'RV-18/V6: money and geo suites skip whenever DB_CONNECTION is not mysql. '
            .'Under CI_REQUIRE_MYSQL=1 that skip is a FAILURE, not a pass.'
        );

        // And prove the named suites exist, so this list cannot rot into fiction.
        foreach (self::MONEY_OR_GEO_SUITES as $class) {
            $this->assertTrue(
                $this->testFileExists($class),
                "RV-18: {$class} is listed as a money/geo suite but no longer exists; "
                .'update MONEY_OR_GEO_SUITES.'
            );
        }
    }

    /**
     * Recursive scan for a test class file by name.
     *
     * Uses a real iterator, NOT glob('tests/**'): PHP's glob does not treat `**` as
     * recursive, so a depth-based glob silently misses a suite moved one folder deeper
     * and would false-red the CI green floor — the very "flaky gate people start to
     * ignore" failure RV-18 exists to remove.
     */
    private function test_file_exists(string $class): bool
    {
        $needle = $class.'.php';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('tests'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === $needle) {
                return true;
            }
        }

        return false;
    }

    private function ciRequiresMysql(): bool
    {
        $value = getenv('CI_REQUIRE_MYSQL');
        if ($value === false) {
            $value = $_ENV['CI_REQUIRE_MYSQL'] ?? $_SERVER['CI_REQUIRE_MYSQL'] ?? null;
        }

        return $value === '1' || $value === 'true';
    }

    /**
     * A skip that is the CORRECT behaviour outside CI. Announced, not silent: it prints
     * why, so nobody reads a skip here as a pass.
     */
    private function markTestSkippedNotRequired(): void
    {
        $this->markTestSkipped(
            'RV-18 driver alarm only fires when CI_REQUIRE_MYSQL=1 (set by the CI '
            .'workflows). Locally the default sqlite config is fine.'
        );
    }
}
