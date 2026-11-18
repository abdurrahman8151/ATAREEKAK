<?php

namespace Tests\Feature\Review;

use App\Modules\Money\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CommittedRowLeakDetector;
use Tests\TestCase;

/**
 * The committed-row leak guard, checked as a component.
 *
 * The guard itself runs after every test in the suite (see `GuardsAgainstCommittedLeaks`), so its
 * WIRING is exercised thousands of times on a green run - but a guard that is never seen to fail is
 * a guard nobody can trust. These tests drive the detector against a row that really exists, so
 * "reports what is there" and "reports nothing when the tables are empty" are both observed.
 *
 * They do NOT leak: the row is deleted inside the same test, which is exactly what the guard
 * demands of every class.
 */
class CommittedRowLeakGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_guard_watches_every_base_table_except_migrations(): void
    {
        $schemaTables = array_map(
            static fn (mixed $table): string => (string) $table,
            Schema::getTableListing()
        );

        $expected = array_values(array_diff($schemaTables, array_keys(CommittedRowLeakDetector::EXCLUDED)));
        sort($expected);

        $guarded = CommittedRowLeakDetector::guardTables();

        $this->assertSame(
            $expected,
            $guarded,
            'The guarded list must be DERIVED from the schema minus the named exclusions. A migration '
            .'that adds a table must be guarded without anybody remembering to edit a list.'
        );

        // The seven tables the RV45 pollution damaged must still be inside the derived set: a
        // widening that quietly dropped one would be a regression.
        foreach (['wallets', 'users', 'rides', 'bookings', 'user_ratings', 'profile_comments', 'refresh_tokens'] as $table) {
            $this->assertContains($table, $guarded, "{$table} must stay guarded");
        }

        $this->assertNotContains('migrations', $guarded, 'migrations is written by the runner outside any test transaction');
        $this->assertGreaterThan(20, count($guarded), 'the derived list must be the whole schema, not a hand-picked slice');
    }

    public function test_the_detector_reports_a_leak_from_a_table_the_old_seven_table_guard_never_watched(): void
    {
        $this->assertSame([], CommittedRowLeakDetector::detect(), 'a rolled-back test must leave every guarded table empty');

        // `notifications` was NOT one of the seven. A row left behind here used to be invisible to
        // the guard even though it changes what a later test sees (an unread badge, a count).
        DB::table('notifications')->insert([
            'title' => 'leak probe',
            'message' => 'a row the old guard could not see',
            'type' => 'general',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $leaks = CommittedRowLeakDetector::detect();

        $this->assertArrayHasKey('notifications', $leaks, 'a leak outside the old seven tables must be reported');
        $this->assertSame(1, $leaks['notifications']);

        DB::table('notifications')->where('title', 'leak probe')->delete();

        $this->assertSame([], CommittedRowLeakDetector::detect(), 'deleting the row must clear the report');
    }

    public function test_the_detector_reports_a_row_that_exists_and_nothing_when_the_tables_are_empty(): void
    {
        $this->assertSame([], CommittedRowLeakDetector::detect(), 'a rolled-back test must leave every guarded table empty');

        $wallet = Wallet::create([
            'user_id' => null,
            'phone_number' => '0999000001',
            'wallet_number' => 'WLT-LEAKPROBE1',
            'balance' => 0,
        ]);

        $leaks = CommittedRowLeakDetector::detect();

        $this->assertArrayHasKey('wallets', $leaks, 'a wallet row that exists must be reported');
        $this->assertSame(1, $leaks['wallets'], 'the report must carry the row COUNT, not just the table name');
        $this->assertSame('wallets=1', CommittedRowLeakDetector::describe($leaks));

        $wallet->delete();

        $this->assertSame([], CommittedRowLeakDetector::detect(), 'deleting the row must clear the report');
    }
}
