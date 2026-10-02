<?php

namespace Tests\Feature\Review;

use App\Enums\LedgerType;
use App\Models\Wallet;
use Carbon\Carbon;
use Database\Seeders\Atarikaktestseeder;
use Database\Seeders\BulkRideSeeder;
use Database\Seeders\DriverSeeder;
use Database\Seeders\PassengerSeeder;
use Database\Seeders\RefusesProduction;
use Database\Seeders\SpecialAccountSeeder;
use Database\Seeders\SyrideSeeder;
use Database\Seeders\SystemAdminSeeder;
use Database\Seeders\SystemWalletSeeder;
use Database\Seeders\UserRealFlowSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * RV-39 test fixture — a zero-data seeder that records how far execution reached.
 *
 * Declared INSIDE the test file on purpose: the scoped runner hands every changed
 * tests/*.php file to PHPUnit as a suite candidate, and a standalone non-TestCase
 * file trips the loader ("does not extend PHPUnit\Framework\TestCase"). Used to
 * prove BOTH directions through the real `php artisan db:seed --class=... --force`
 * seam (SeedCommand -> container->make -> Seeder::__invoke -> run()): a
 * non-production environment completes run(); production is refused after entering
 * run() but before its body does anything.
 *
 * It is deliberately NOT one of the four guarded seeders: BulkRideSeeder's own
 * early return depends on data neighbouring tests may have committed (RV-37's
 * recorded cross-file leakage), and if verified drivers happened to exist it would
 * bulk-insert 500,000 rides inside the test process. $outcome is static so the
 * test can read it across the container boundary.
 */
class ProductionGuardProbeSeeder extends Seeder
{
    use RefusesProduction;

    /**
     * 'entered' when run() was reached, upgraded to 'ran' when the body completed.
     * A refusal therefore proves as outcome === 'entered': the real seam executed
     * run(), and only the guard stopped it.
     */
    public static ?string $outcome = null;

    public function run(): void
    {
        self::$outcome = 'entered';

        $this->refuseProduction();

        self::$outcome = 'ran';
    }
}

/**
 * RV-39 — seeders: production guard, filename==classname, truncate completeness,
 * shared ledger vocabulary, config-resolved system wallets, per-wallet unique phones.
 *
 * The audit (R2 sec 3) recorded five defects; each section below pins one closed:
 *
 *  1. No production guard: the four state-forging seeders could run against the live
 *     database with `db:seed --class=... --force`. RefusesProduction throws first thing
 *     in run() while the app environment is production. The refusal is proven on the
 *     REAL run() of all four (the throw precedes every write, so the probe is
 *     side-effect free); the allowed path is proven through the real
 *     `php artisan db:seed` entry point. Deploy seeders stay unguarded on purpose —
 *     they are the idempotent creators a production bootstrap is supposed to run.
 *  2. Syrideseeder.php declared class SyrideSeeder — fine on Windows, broken on
 *     case-sensitive Linux without an optimized classmap. Renamed; every file in
 *     database/seeders is now swept: filename == declared type name, namespace matches
 *     composer's own psr-4 map (the RV-35 rule, extended to this directory).
 *  3. truncateTables() omitted tables whose FKs point into the truncated set
 *     (noshow_reports, refresh_tokens, otps), orphaning their rows under
 *     FOREIGN_KEY_CHECKS=0. TRUNCATE_TABLES is now checked against the LIVE FK graph:
 *     the closure rule is derived from information_schema after migrations, so a new
 *     FK into the truncated set fails this test until the list grows with it.
 *  4. The seeder hand-wrote a THIRD ledger dialect (ride_payment / escrow_hold /
 *     ride_creation_fee_received) that no reader in app/ queries — so seeded money was
 *     invisible to the admin reports. LedgerType is now the shared vocabulary; a
 *     ratchet asserts every value written into wallet_transactions.type from app/ or
 *     the seeders is a LedgerType case (services still pass literals; converting them
 *     is AF-6's job — the enum pins the VOCABULARY, not the call style).
 *  5. The phantom SyCash (+963999000001, no service reads it) and missing Primary
 *     wallet: resolveSystemWallets() delegates to SystemWalletSeeder and resolves both
 *     by the config/admin.php phones — the exact phones AdminReportService sums. The
 *     Driver/Passenger duplicate-phone crash (same UNIQUE phone on 10 wallets) is
 *     proven fixed by running both seeders end to end: 20 wallets, 20 distinct phones.
 *
 * Genuinely NOT verified here (documented in the audit record): the physical
 * truncateTables() call cannot run inside a RefreshDatabase transaction — TRUNCATE is
 * DDL and would commit the wrapper — so criterion 3 is proven structurally (list vs
 * live FK graph) plus per-table existence, not by a runtime truncation.
 */
class RV39SeederHygieneTest extends TestCase
{
    use RefreshDatabase;

    /** The four seeders R2 sec 3 named as runnable-destructive. */
    private const GUARDED = [
        SyrideSeeder::class,
        BulkRideSeeder::class,
        Atarikaktestseeder::class,
        UserRealFlowSeeder::class,
    ];

    /** Production bootstrap seeders: idempotent creators, must NOT carry the guard. */
    private const UNGUARDED = [
        SpecialAccountSeeder::class,
        SystemAdminSeeder::class,
        SystemWalletSeeder::class,
    ];

    // =====================================================================
    // 1. Production guard
    // =====================================================================

    public function test_every_state_forging_seeder_uses_the_refuses_production_trait(): void
    {
        foreach (self::GUARDED as $class) {
            $this->assertContains(
                RefusesProduction::class,
                class_uses_recursive($class),
                "{$class} must carry the RefusesProduction guard"
            );
        }
    }

    public function test_guarded_seeder_refuses_in_production_before_touching_anything(): void
    {
        // The throw must come from the REAL run() entry point of every guarded seeder.
        // refuseProduction() is the first statement, so the probe cannot write: no
        // truncate, no bulk insert, no frozen clock, no mutation of user #36.
        // Emptiness is measured as a DELTA, not as "0 rows": RV-37 recorded that the
        // suite has cross-file committed-state leakage, so an absolute "table is empty"
        // assertion would fail on a neighbour's leftovers, not on a guard defect.
        $before = [
            'users' => DB::table('users')->count(),
            'rides' => DB::table('rides')->count(),
            'bookings' => DB::table('bookings')->count(),
            'frozen' => Carbon::hasTestNow(),
        ];

        foreach (self::GUARDED as $class) {
            $this->app['env'] = 'production';

            try {
                (new $class)->run();
                $this->fail("{$class} ran to completion under production - the guard did not fire");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(
                    $class,
                    $e->getMessage(),
                    'the refusal must name the seeder it refused'
                );
                $this->assertStringContainsString('production', $e->getMessage());
            } finally {
                $this->app['env'] = 'testing';   // never leak the environment
            }

            $after = [
                'users' => DB::table('users')->count(),
                'rides' => DB::table('rides')->count(),
                'bookings' => DB::table('bookings')->count(),
                'frozen' => Carbon::hasTestNow(),
            ];
            $this->assertSame(
                $before,
                $after,
                "{$class} refusal must not write, truncate, re-seed, or freeze the clock"
            );
        }
    }

    public function test_guarded_run_methods_call_refuse_production_first(): void
    {
        // Causality, not coincidence: a seeder that merely `uses` the trait but forgets
        // the call is still open. Every guarded run() must START with the guard
        // (a doc comment line is allowed between the brace and the call).
        foreach (self::GUARDED as $class) {
            $src = (string) file_get_contents((new ReflectionClass($class))->getFileName());

            $this->assertMatchesRegularExpression(
                '/function run\(\)[^{]*\{\s*(?:\/\/[^\n]*\n\s*)*\$this->refuseProduction\(\);/',
                $src,
                "{$class}::run() must call refuseProduction() as its first statement"
            );
        }
    }

    public function test_deploy_seeders_stay_unguarded_and_production_runnable(): void
    {
        // The other half of the boundary: the guard must not creep onto the idempotent
        // deploy seeders, or a production bootstrap loses the only sanctioned way to
        // create system accounts and system wallets.
        foreach (self::UNGUARDED as $class) {
            $this->assertNotContains(
                RefusesProduction::class,
                class_uses_recursive($class),
                "{$class} is a deploy seeder and must not refuse production"
            );
        }
    }

    public function test_the_allowed_and_refused_paths_both_run_through_the_real_db_seed_entry_point(): void
    {
        // The exact audited invocation shape: `db:seed --class=<Seeder> --force`.
        // --force matters: without it SeedCommand::confirmToProceed() refuses in
        // production BEFORE the seeder is even resolved, so the framework's own gate
        // would mask the guard. With --force the audited bypass is live and only
        // RefusesProduction stands between the command and the data.
        //
        // A probe seeder rather than one of the four real ones: BulkRideSeeder's own
        // early return depends on data neighbouring tests leaked, and if verified
        // drivers existed it would bulk-insert 500,000 rides inside this test. The
        // probe preserves the whole seam: SeedCommand -> container->make ->
        // Seeder::__invoke -> run() -> refuseProduction().
        ProductionGuardProbeSeeder::$outcome = null;

        $this->artisan('db:seed', ['--class' => ProductionGuardProbeSeeder::class])
            ->assertExitCode(0);
        $this->assertSame('ran', ProductionGuardProbeSeeder::$outcome, 'non-production must reach run() through db:seed');

        ProductionGuardProbeSeeder::$outcome = null;
        $this->app['env'] = 'production';
        try {
            // The guard throws inside run(); the console kernel converts that to exit 1
            // (and may rethrow under testing) - either way, the probe body completed
            // nothing. 'entered' proves the real seam reached run() and the guard, not
            // some pre-seed framework gate, was what stopped it.
            try {
                $exit = $this->artisan('db:seed', [
                    '--class' => ProductionGuardProbeSeeder::class,
                    '--force' => true,
                ])->run();
                $this->assertSame(1, $exit, 'production + --force must not exit successful');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(ProductionGuardProbeSeeder::class, $e->getMessage());
            }
            $this->assertSame(
                'entered',
                ProductionGuardProbeSeeder::$outcome,
                'run() must be reached through the real seam, then stopped by the guard before completing'
            );
        } finally {
            $this->app['env'] = 'testing';
            ProductionGuardProbeSeeder::$outcome = null;
        }
    }

    // =====================================================================
    // 2. filename == declared class (PSR-4), database/seeders swept
    // =====================================================================

    public function test_the_system_seeder_file_is_renamed_to_match_its_class(): void
    {
        $dir = database_path('seeders');

        $this->assertFileExists($dir.'/SyrideSeeder.php');

        // file_exists() is CASE-INSENSITIVE on this (Windows) checkout, so
        // assertFileDoesNotExist('Syrideseeder.php') could never pass here. Enumerate the
        // real directory entries and compare the stored name byte-for-byte instead -
        // which is also exactly the property Linux's case-sensitive lookup depends on.
        $entries = array_map('strval', scandir($dir) ?: []);
        $this->assertNotContains(
            'Syrideseeder.php',
            $entries,
            'the lowercase-name file was the Linux autoload trap (RV-39)'
        );
        $this->assertContains('SyrideSeeder.php', $entries, 'the stored filename must be the PascalCase one');

        // The class resolves through composer's autoloader exactly the way SeedCommand
        // resolves `db:seed --class=SyrideSeeder`.
        $this->assertTrue(class_exists(SyrideSeeder::class));
        $this->assertSame(
            $dir.DIRECTORY_SEPARATOR.'SyrideSeeder.php',
            (new ReflectionClass(SyrideSeeder::class))->getFileName(),
            'the autoloaded class must come from the PascalCase file, not a stale map'
        );
    }

    public function test_every_seeder_file_declares_the_name_its_path_promises(): void
    {
        // The RV-35 namespace==path rule, extended to database/seeders. The prefix and
        // its directory are read from the repo's own composer.json, never assumed.
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $map = $composer['autoload']['psr-4'];
        $prefix = array_search('database/seeders/', $map, true);
        $this->assertIsString($prefix, 'composer.json must map a namespace to database/seeders/');
        $namespace = trim($prefix, '\\');

        $failures = [];
        foreach (glob(database_path('seeders').'/*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            $expected = basename($file, '.php');

            if (! preg_match('/^\s*namespace\s+([^;]+);/m', $src, $m)) {
                $failures[] = "{$expected}: no namespace declaration";

                continue;
            }
            if (trim($m[1]) !== $namespace) {
                $failures[] = "{$expected}: namespace {$m[1]} != {$namespace}";
            }
            if (! preg_match('/^\s*(?:final\s+|abstract\s+)?(?:class|trait|interface)\s+(\w+)/m', $src, $d)) {
                $failures[] = "{$expected}: no declarable type found";

                continue;
            }
            if ($d[1] !== $expected) {
                $failures[] = "{$expected}.php declares '{$d[1]}' - PSR-4 breaks on case-sensitive filesystems";
            }
        }

        $this->assertSame([], $failures, 'seeders must satisfy filename == declared type name');
    }

    // =====================================================================
    // 3. Truncate closure, derived from the live FK graph
    // =====================================================================

    public function test_truncate_list_covers_every_table_whose_rows_orphan_under_it(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('FK-graph derivation needs MySQL (the audited and scratch schema).');
        }

        $list = SyrideSeeder::TRUNCATE_TABLES;

        // (a) The exact tables R2 sec 3 named as missing:
        foreach (['noshow_reports', 'refresh_tokens', 'otps'] as $table) {
            $this->assertContains($table, $list, "RV-39 named {$table} as a missing truncation");
        }

        // (b) Closure rule from the ACTUAL migrated schema: with FOREIGN_KEY_CHECKS=0,
        // truncating a parent orphans every child referencing it, so each such child
        // must be in the list - unless it hangs off employees, which the seeder
        // deliberately preserves (staff are re-created, not truncated).
        $fkRows = DB::select(
            'SELECT TABLE_NAME child, REFERENCED_TABLE_NAME parent
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL'
        );
        $this->assertNotEmpty($fkRows, 'the test DB must carry the real FK graph (MySQL scratch)');

        $truncated = array_flip($list);
        $violations = [];
        foreach ($fkRows as $fk) {
            $parentPreserved = $fk->parent === 'employees';
            if (isset($truncated[$fk->parent]) && ! isset($truncated[$fk->child]) && ! $parentPreserved) {
                $violations[] = "{$fk->child} references truncated {$fk->parent} but is not truncated";
            }
        }
        $this->assertSame(
            [],
            $violations,
            'new FKs into the truncated set must extend SyrideSeeder::TRUNCATE_TABLES in the same change'
        );

        // (c) Every named table exists in the real schema (no stale names in the list).
        // SHOW TABLES does not accept bound parameters, so read information_schema.
        $rows = DB::select('SELECT TABLE_NAME n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        $existing = array_map('strtolower', array_column($rows, 'n'));
        foreach ($list as $table) {
            $this->assertContains(
                strtolower($table),
                $existing,
                "TRUNCATE_TABLES names a table that does not exist: {$table}"
            );
        }
    }

    // =====================================================================
    // 4. Shared ledger vocabulary (the third dialect cannot come back)
    // =====================================================================

    public function test_the_seeder_uses_the_shared_enum_and_drops_the_third_dialect(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SyrideSeeder::class))->getFileName());

        // Raw string literals in the named-arg ledger position are gone entirely:
        $this->assertSame(
            0,
            preg_match_all("/\btype:\s*'/", $src),
            'SyrideSeeder ledger writes must pass LedgerType enum cases, never strings'
        );

        // The audited third-dialect names appear nowhere in ledger position:
        foreach (['ride_payment', 'escrow_hold'] as $dead) {
            $this->assertStringNotContainsString("'type' => '{$dead}'", $src);
            $this->assertStringNotContainsString("type: '{$dead}'", $src);
        }
    }

    public function test_every_wallet_transaction_type_written_anywhere_is_a_shared_ledger_type(): void
    {
        // Ratchet against the whole drift class T1-2 proved (escrow_release vs
        // escrow_released: writer and reader disagreed, report summed zero forever).
        // Every value that reaches wallet_transactions.type from app/ or the seeders
        // must be a LedgerType case: literals directly, and variables traced to their
        // literal assignments in the same file. enum-expressions cannot drift by
        // construction. Services passing literals is the AF-6 debt this pins the
        // VOCABULARY around (not the call style).
        $values = LedgerType::values();
        $offenders = [];

        $files = [];
        foreach (['app', 'database/seeders'] as $dir) {
            $rii = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($rii as $f) {
                if ($f->isFile() && $f->getExtension() === 'php'
                    && str_contains((string) file_get_contents($f->getPathname()), 'WalletTransaction::create(')) {
                    $files[] = $f->getPathname();
                }
            }
        }
        $this->assertNotEmpty($files, 'the scan must find the real ledger writers');

        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            $rel = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

            foreach ($this->walletCreateBlocks($src) as $block) {
                if (! preg_match("/'type'\s*=>\s*([^,\n]+)/", $block, $m)) {
                    continue;   // no explicit type key
                }
                $expr = trim($m[1]);

                if (str_starts_with($expr, "'")) {
                    $lit = trim(substr($expr, 0, -1), "'");
                    if (! in_array($lit, $values, true)) {
                        $offenders[] = "{$rel}: 'type' => '{$lit}' is not a LedgerType value";
                    }
                } elseif (str_starts_with($expr, '$')) {
                    $var = preg_quote(explode('->', $expr)[0], '/');
                    preg_match_all("/{$var}\s*=\s*'([^']+)'/", $src, $assigns);
                    foreach ($assigns[1] as $lit) {
                        if (! in_array($lit, $values, true)) {
                            $offenders[] = "{$rel}: {$var} = '{$lit}' (reaches wallet_transactions.type) is not a LedgerType value";
                        }
                    }
                }
                // LedgerType::X or X->value: enum-typed, drift is a compile-visible error.
            }
        }

        $this->assertSame([], $offenders, 'wallet_transactions.type must come from the shared LedgerType vocabulary');
    }

    /**
     * @return list<string> the full WalletTransaction::create([...]) call blocks in $src
     */
    private function walletCreateBlocks(string $src): array
    {
        // Comments are stripped first so apostrophes in prose (e.g. 'WR-'.$id examples)
        // cannot flip the quote state machine.
        $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? $src;
        $src = preg_replace('#//[^\n]*#', '', $src) ?? $src;

        $needle = 'WalletTransaction::create(';
        $blocks = [];
        $offset = 0;

        while (($start = strpos($src, $needle, $offset)) !== false) {
            $i = $start + strlen($needle) - 1;  // at '(' of create(
            $depth = 0;
            $len = strlen($src);
            $inString = null;
            $escaped = false;
            for (; $i < $len; $i++) {
                $c = $src[$i];
                if ($inString === "'") {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($c === '\\') {
                        $escaped = true;
                    } elseif ($c === "'") {
                        $inString = null;
                    }

                    continue;
                }
                if ($inString === '"') {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($c === '\\') {
                        $escaped = true;
                    } elseif ($c === '"') {
                        $inString = null;
                    }

                    continue;
                }
                if ($c === "'" || $c === '"') {
                    $inString = $c;
                } elseif ($c === '(') {
                    $depth++;
                } elseif ($c === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }
            $blocks[] = substr($src, $start, $i - $start + 1);
            $offset = $i;
        }

        return $blocks;
    }

    // =====================================================================
    // 5. System wallets resolved by config, no phantom wallet
    // =====================================================================

    public function test_resolve_system_wallets_produces_the_wallets_the_money_services_read(): void
    {
        $seeder = new SyrideSeeder;
        $resolve = (new ReflectionClass($seeder))->getMethod('resolveSystemWallets');

        $resolve->invoke($seeder);

        $sycash = Wallet::where('phone_number', config('admin.sycash.phone'))->first();
        $primary = Wallet::where('phone_number', config('admin.system_admin.phone'))->first();

        // The exact two rows AdminReportService resolves when it sums escrow in/out.
        $this->assertNotNull($sycash, 'SyCash wallet must exist on the config phone');
        $this->assertNotNull($primary, 'RV-39: previously NO Primary wallet was created at all');
        $this->assertNull($sycash->user_id, 'system wallet must stay platform-owned');
        $this->assertNull($primary->user_id);

        $ref = new ReflectionClass($seeder);
        foreach (['sycashWallet' => $sycash, 'primaryWallet' => $primary] as $prop => $row) {
            $p = $ref->getProperty($prop);
            $this->assertSame(
                $row->id,
                $p->getValue($seeder)?->id,
                "\${$prop} must hold the config-resolved wallet, not a private one"
            );
        }

        // No phantom wallet was conjured on the old hard-coded phone:
        $this->assertSame(0, Wallet::where('phone_number', '+963999000001')->count());
        $this->assertSame(0, Wallet::where('wallet_number', 'SYR-ESCROW-001')->count());

        // Idempotent re-run (SystemWalletSeeder is a no-op on existing system wallets).
        $resolve->invoke($seeder);
        $this->assertSame(1, Wallet::where('phone_number', config('admin.sycash.phone'))->count());
        $this->assertSame(1, Wallet::where('phone_number', config('admin.system_admin.phone'))->count());
    }

    public function test_the_phantom_wallet_literals_are_gone_from_seeder_code(): void
    {
        $src = (string) file_get_contents((new ReflectionClass(SyrideSeeder::class))->getFileName());

        $this->assertStringNotContainsString("'phone_number' => '+963999000001'", $src);
        $this->assertStringNotContainsString("'wallet_number' => 'SYR-ESCROW-001'", $src);
    }

    // =====================================================================
    // 6. Driver/Passenger seeders: per-wallet unique phones
    // =====================================================================

    public function test_driver_and_passenger_seeders_create_ten_wallets_each_with_distinct_phones(): void
    {
        // The audit: wallets.phone_number is UNIQUE, yet both seeders gave every wallet
        // self::COMM_NUMBER, so a run threw a duplicate-key QueryException on the second
        // Wallet::create - the "10 verified drivers with wallets" flow never completed.
        // Run both through the real artisan entry point (container-injected
        // VerificationRepository included) and prove +10 users with wallets per seeder
        // and 20 new distinct phones. Counts are deltas: committed cross-file state is
        // RV-37 debt and must not decide this assertion.
        $usersBefore = DB::table('users')->count();
        $walletsBefore = Wallet::count();

        $this->artisan('db:seed', ['--class' => DriverSeeder::class])->assertExitCode(0);
        $this->artisan('db:seed', ['--class' => PassengerSeeder::class])->assertExitCode(0);

        $this->assertSame($usersBefore + 20, DB::table('users')->count(), 'each seeder must complete all 10 users');
        $this->assertSame($walletsBefore + 20, Wallet::count(), 'each seeded user must own exactly one new wallet');

        // The 20 phones this run created are pairwise distinct (the duplicate-key fix):
        $newPhones = Wallet::query()
            ->where('phone_number', 'like', '+9639833372%')
            ->orWhere('phone_number', 'like', '+9639833373%')
            ->pluck('phone_number')->all();
        $this->assertCount(
            20,
            array_unique($newPhones),
            'every seeded wallet needs its own phone (phone_number is UNIQUE)'
        );

        // The shared-constant shape is gone from both sources.
        foreach ([DriverSeeder::class, PassengerSeeder::class] as $class) {
            $src = (string) file_get_contents((new ReflectionClass($class))->getFileName());
            $this->assertStringNotContainsString("'phone_number' => self::COMM_NUMBER", $src);
        }
    }

    public function test_wallet_phone_numberspaces_do_not_collide_between_the_two_seeders(): void
    {
        // Driver base ...72 vs Passenger base ...73: the two seeders must be able to run
        // into the same database (they did not, before: identical constant in both).
        $this->artisan('db:seed', ['--class' => DriverSeeder::class])->assertExitCode(0);
        $this->artisan('db:seed', ['--class' => PassengerSeeder::class])->assertExitCode(0);

        $driverPhones = Wallet::where('phone_number', 'like', '+9639833372%')->pluck('phone_number')->all();
        $passengerPhones = Wallet::where('phone_number', 'like', '+9639833373%')->pluck('phone_number')->all();

        $this->assertCount(10, $driverPhones);
        $this->assertCount(10, $passengerPhones);
        $this->arrayIntersectEmpty($driverPhones, $passengerPhones);
    }

    /** @param list<string> $a @param list<string> $b */
    private function arrayIntersectEmpty(array $a, array $b): void
    {
        $this->assertSame([], array_intersect($a, $b), 'the two seeders must not hand out the same wallet phone');
    }
}
