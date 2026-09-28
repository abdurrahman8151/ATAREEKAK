<?php

namespace Tests\Feature\T3Batch;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T3 batch — schema effects, measured on the real MySQL 8.2.0 after a full
 * migrate (RefreshDatabase), never asserted by reading migration files.
 *
 *   T3-2  money columns all decimal(15,2) with defaults preserved
 *   T3-5  no duplicated index column-sets remain on rides/bookings
 *         (+ the ledger's redundant plain transaction_id index is gone)
 *   T3-6  the MySQL-only raw migrations skip cleanly on a non-mysql driver
 *         instead of fataling
 */
class MigrationEffectsBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('Requires the production-compatible MySQL connection.');
        }
        parent::setUp();
    }

    private function schema(): string
    {
        return (string) DB::connection()->getDatabaseName();
    }

    // ── T3-2 ────────────────────────────────────────────────────────────────
    public function test_every_money_column_is_decimal_15_2(): void
    {
        $rows = DB::select(
            'SELECT TABLE_NAME, COLUMN_NAME, NUMERIC_PRECISION p, NUMERIC_SCALE s
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND DATA_TYPE = "decimal"
               AND TABLE_NAME IN ("wallets","wallet_requests","wallet_transactions")
               AND COLUMN_NAME IN ("balance","cash_ride_debt","amount","previous_balance","new_balance")',
            [$this->schema()]
        );

        $this->assertCount(6, $rows, 'all six money columns present');
        foreach ($rows as $r) {
            $this->assertSame(15, (int) $r->p, "{$r->TABLE_NAME}.{$r->COLUMN_NAME} precision");
            $this->assertSame(2, (int) $r->s, "{$r->TABLE_NAME}.{$r->COLUMN_NAME} scale");
        }
    }

    public function test_wallet_default_survived_the_widening(): void
    {
        // ->change() in Laravel is known to silently drop column defaults.
        // wallets.balance must still DEFAULT 0.00 after the widen.
        $def = DB::select(
            'SELECT COLUMN_DEFAULT d FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "wallets" AND COLUMN_NAME = "balance"',
            [$this->schema()]
        )[0] ?? null;

        $this->assertNotNull($def);
        $this->assertStringContainsString('0.00', (string) $def->d);
    }

    // ── T3-5 ────────────────────────────────────────────────────────────────
    public function test_no_index_column_set_is_duplicated(): void
    {
        foreach (['rides', 'bookings', 'wallet_transactions'] as $table) {
            $groups = DB::select(
                'SELECT cols, COUNT(*) n FROM (
                    SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols
                    FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                    GROUP BY INDEX_NAME
                 ) t GROUP BY t.cols HAVING COUNT(*) > 1',
                [$this->schema(), $table]
            );

            $this->assertSame(
                [],
                $groups,
                "table $table still holds duplicate indexes over the same column set"
            );
        }
    }

    public function test_the_surviving_status_indexes_are_present(): void
    {
        // Dedup must not have over-deleted: the kept names still exist.
        $have = fn (string $table): array => array_map(
            static fn ($r) => $r->INDEX_NAME,
            DB::select(
                'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$this->schema(), $table]
            )
        );

        $this->assertContains('rides_driver_status', $have('rides'));
        $this->assertContains('bookings_user_status', $have('bookings'));
        $this->assertContains('bookings_ride_status', $have('bookings'));
        $this->assertContains('wallet_transactions_transaction_id_unique', $have('wallet_transactions'));
        $this->assertNotContains('wallet_transactions_transaction_id_index', $have('wallet_transactions'));
    }

    // ── T3-6 ────────────────────────────────────────────────────────────────
    public function test_mysql_only_migrations_skip_on_a_foreign_driver(): void
    {
        // Prove the driver guards behave: against a genuine non-mysql connection,
        // each previously-bare migration runs up() without throwing. The stub
        // tables matter: without them every migration bails at its own
        // Schema::hasTable() check BEFORE the raw statements, and the test would
        // pass whether the guard existed or not. With the tables present, a
        // disabled guard reaches `ALTER TABLE ... MODIFY/ENUM`, which SQLite
        // cannot parse — the exact fatal the guard exists to prevent.
        config(['database.connections.t36_sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('t36_sqlite');

        try {
            foreach ([
                'CREATE TABLE otps (id INTEGER PRIMARY KEY, phone_number VARCHAR(20), type VARCHAR(30))',
                'CREATE TABLE complaints (id INTEGER PRIMARY KEY, type VARCHAR(30), status VARCHAR(30))',
                'CREATE TABLE bookings (id INTEGER PRIMARY KEY, status VARCHAR(30))',
                'CREATE TABLE users (id INTEGER PRIMARY KEY, address VARCHAR(255))',
                'CREATE TABLE rides (id INTEGER PRIMARY KEY, payment_method VARCHAR(20))',
            ] as $ddl) {
                DB::statement($ddl);
            }

            $files = [
                '2026_04_19_014226_increase_phone_number_length_in_otps_table.php',
                '2026_04_19_014626_add_email_verification_to_otps_type_column.php',
                '2026_07_16_100000_widen_complaints_type_and_status_columns.php',
                '2026_08_01_000001_fix_complaints_type_column.php',
                '2025_07_21_181158_update_bookings_enum_columns.php',
                '2026_05_18_000000_change_users_address_column_to_string.php',
                '2025_05_22_224859_add_pickup_lat_lng_to_rides_table.php',
            ];

            foreach ($files as $f) {
                /** @var \Illuminate\Database\Migrations\Migration $m */
                $m = require base_path('database/migrations/' . $f);
                $m->up(); // must skip via the driver guard, never reach raw SQL
                $this->addToAssertionCount(1);
            }
        } finally {
            DB::setDefaultConnection($previous);
        }
    }
}
