<?php

namespace Tests\Feature\Review;

use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_the_guard_watches_the_seven_tables_the_rv45_pollution_damaged(): void
    {
        $this->assertSame(
            ['wallets', 'users', 'rides', 'bookings', 'user_ratings', 'profile_comments', 'refresh_tokens'],
            CommittedRowLeakDetector::TABLES,
            'The guarded table list is the contract: money, identity (users + refresh tokens), rides, '
            .'bookings and the two review tables. Adding a table is fine; silently dropping one is not.'
        );
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
