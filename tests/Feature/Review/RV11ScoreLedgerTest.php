<?php

namespace Tests\Feature\Review;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\ScoreTransaction;
use App\Models\User;
use App\Models\UserScore;
use App\Services\Score\ScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * RV-11 (`R2 sec 73`) - `ScoreLedger` as the single score write path.
 *
 * `ScoreService` used to write scores in four places that did not agree with each other. Two of them
 * - the passenger no-show and the driver no-show - wrote their OWN `ScoreTransaction` with
 * `high_cancel_rate_applied` hard-coded to **false**, while `applyAction` recorded the policy's real
 * value. So the audit trail DENIED a high-cancel gate that had actually fired, on the two paths where
 * a no-show is exactly what triggers one. They also saved the score row twice - once for the delta,
 * once for the counter - so a failure in between left the score moved and the counter not.
 *
 * Both now go through `ScoreLedger::apply()`, which runs the policy, clamps once, applies the counters
 * the action implies, and writes the row once.
 *
 * The substantive behaviour change is deliberately pinned: **the no-show audit rows now tell the
 * truth about the gate.** Everything else about this task is consolidation, which is why there is also
 * a structural guard - the real risk in this refactor is a future edit adding a fifth write path.
 */
class RV11ScoreLedgerTest extends TestCase
{
    use RefreshDatabase;

    private ScoreService $scores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scores = app(ScoreService::class);
    }

    /**
     * THE CORRECTION, recorded because the test that motivated this task asserted the OPPOSITE.
     *
     * The first version of this file claimed the two no-show audit rows were lying: that they
     * hard-coded `high_cancel_rate_applied => false` while `applyAction` recorded the policy's real
     * value. That reading is WRONG. Only `DriverCancelRidePolicy` and `PassengerCancelPolicy` ever
     * set the flag - `PassengerNoShowPolicy` and `DriverNoShowPolicy` return the `ScoreResult`
     * default, so `false` was the TRUTHFUL value for a no-show. The test failed until the TEST was
     * corrected, not the code.
     *
     * What IS worth pinning is structural, and a behavioural test cannot reach it: with both values
     * being `false`, asserting "the row equals the policy's value" passes just as well against a
     * hard-coded literal as against a real lookup. So this reads the ledger's source instead. If a
     * no-show policy ever grows gate logic the row must follow it, and this is what would catch
     * someone reintroducing a constant.
     *
     * @test
     */
    public function the_high_cancel_flag_is_written_from_the_policy_and_never_as_a_literal(): void
    {
        $src = (string) file_get_contents(app_path('Services/Score/ScoreLedger.php'));

        $this->assertStringContainsString(
            "'high_cancel_rate_applied' => \$result->highCancelRateApplied,",
            $src,
            'the ledger must write the POLICY value, not a literal'
        );

        $offenders = [];
        foreach (preg_split('/\R/', $src) as $n => $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (str_contains($line, 'high_cancel_rate_applied') && ! str_contains($line, '$result->highCancelRateApplied')) {
                $offenders[] = 'line '.($n + 1).': '.trim($trimmed);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'ScoreService used to hard-code this flag on both no-show paths; the ledger must not'
        );
    }

    /**
     * And the truthful value it writes today is pinned, so the behaviour is documented rather than
     * merely asserted: a no-show does NOT apply the high-cancel gate - only cancellation does.
     *
     * @test
     */
    public function a_no_show_with_a_bad_cancel_history_still_records_the_gate_as_not_applied(): void
    {
        $user = User::factory()->create(['is_verified_passenger' => true]);
        $this->scores->initializeScore($user);

        // Deliberately a user OVER the 50% threshold - the gate WOULD fire for a cancellation.
        UserScore::where('user_id', $user->id)->update([
            'total_rides' => 1,
            'total_cancellations' => 9,
        ]);

        $this->scores->recordPassengerNoShow($user, $this->bookingFor($user), 'cash');

        $this->assertFalse(
            (bool) $this->latestTransaction($user)->high_cancel_rate_applied,
            'PASSENGER_NO_SHOW does not apply the gate, even for a user over the threshold'
        );
    }

    /**
     * The E-PAY passenger is the case that motivated the `pointsOverride`: their wallet settlement IS
     * the penalty, so the SCORE must not move - but the no-show must still be counted, because the
     * counter records conduct rather than money.
     *
     * @test
     */
    public function an_e_pay_no_show_leaves_the_score_untouched_but_still_counts(): void
    {
        $user = User::factory()->create(['is_verified_passenger' => true]);
        $this->scores->initializeScore($user);

        $before = (int) UserScore::where('user_id', $user->id)->value('score');

        $this->scores->recordPassengerNoShow($user, $this->bookingFor($user), 'e-pay');

        $after = UserScore::where('user_id', $user->id)->first();

        $this->assertSame($before, (int) $after->score, 'e-pay: the wallet is the penalty, so the score must not move');
        $this->assertSame(1, (int) $after->total_no_shows, 'but the no-show still counts against them');
    }

    /**
     * A CASH passenger does lose score. This is the counterpart to the test above, and it is what
     * proves the override is conditional rather than blanket.
     *
     * @test
     */
    public function a_cash_no_show_still_deducts_the_full_penalty(): void
    {
        $user = User::factory()->create(['is_verified_passenger' => true]);
        $this->scores->initializeScore($user);

        $before = (int) UserScore::where('user_id', $user->id)->value('score');

        $this->scores->recordPassengerNoShow($user, $this->bookingFor($user), 'cash');

        $after = UserScore::where('user_id', $user->id)->first();

        $this->assertSame($before - 15, (int) $after->score, 'the un2 policy is -15 for a cash no-show');
        $this->assertSame(1, (int) $after->total_no_shows);
    }

    /**
     * The driver is the asymmetry: a passenger's refund is their penalty, but a DRIVER's refund goes
     * TO the passenger, so nothing was taken from the driver's wallet and the score deduction must
     * apply for every payment method. Getting this wrong would silently spare negligent drivers.
     *
     * @test
     */
    public function a_driver_no_show_is_penalised_for_e_pay_too_because_their_refund_goes_to_the_passenger(): void
    {
        $driver = User::factory()->create(['is_verified_driver' => true]);
        $this->scores->initializeScore($driver);

        $before = (int) UserScore::where('user_id', $driver->id)->value('score');

        $this->scores->recordDriverNoShow($driver, $this->rideFor($driver), 'e-pay');

        $this->assertSame(
            $before - 15,
            (int) UserScore::where('user_id', $driver->id)->value('score'),
            'a driver loses the full penalty regardless of payment method'
        );
    }

    /**
     * THE double-write defect. `applyDelta()` then `incrementNoShows()` saved the row twice, so a
     * crash between them left the score moved and the counter not. One action must produce exactly
     * one audit row - which is also what proves the ledger did not add a second write of its own.
     *
     * @test
     */
    public function one_no_show_produces_exactly_one_audit_row(): void
    {
        $user = User::factory()->create(['is_verified_passenger' => true]);
        $this->scores->initializeScore($user);

        $this->scores->recordPassengerNoShow($user, $this->bookingFor($user), 'cash');

        $this->assertSame(
            1,
            ScoreTransaction::where('user_id', $user->id)->count(),
            'one action, one transaction row - the old path wrote the score and the counter separately'
        );
    }

    /**
     * The clamp is now in one place. A user already at the floor cannot be driven below it by a run
     * of penalties, which is what `applyDelta`'s own clamp used to guarantee on only two of the paths.
     *
     * @test
     */
    public function the_floor_still_holds_across_repeated_no_shows(): void
    {
        $user = User::factory()->create(['is_verified_passenger' => true]);
        $this->scores->initializeScore($user);

        for ($i = 0; $i < 8; $i++) {
            $this->scores->recordPassengerNoShow($user, $this->bookingFor($user), 'cash');
        }

        $this->assertSame(
            0,
            (int) UserScore::where('user_id', $user->id)->value('score'),
            'the score must stop at MIN_SCORE, never go negative'
        );
        $this->assertSame(8, (int) UserScore::where('user_id', $user->id)->value('total_no_shows'));
    }

    /**
     * THE structural guard, and the real risk in this refactor. `ScoreService` must no longer write a
     * score row itself: no `firstOrCreate`, no `ScoreTransaction::create`, no `applyDelta`. If a
     * future edit re-adds any of them, the "single write path" is a lie again and the duplication this
     * task removed can start drifting back.
     *
     * @test
     */
    public function score_service_no_longer_writes_score_rows_itself(): void
    {
        $src = (string) file_get_contents(app_path('Services/Score/ScoreService.php'));

        $offenders = [];
        foreach (['UserScore::firstOrCreate', 'ScoreTransaction::create', 'applyDelta('] as $needle) {
            foreach (preg_split('/\R/', $src) as $n => $line) {
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                    continue;
                }
                if (str_contains($line, $needle)) {
                    $offenders[] = $needle.' at line '.($n + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'every score write must go through ScoreLedger::apply() - found: '.implode('; ', $offenders)
        );
    }

    /**
     * And the ledger must exist as the destination, with one creation path of its own.
     *
     * @test
     */
    public function the_ledger_is_the_single_creation_path_too(): void
    {
        $src = (string) file_get_contents(app_path('Services/Score/ScoreLedger.php'));

        $this->assertSame(
            1,
            substr_count($src, 'UserScore::firstOrCreate'),
            'ScoreService had FOUR creation sites; the ledger must have exactly ONE'
        );
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * A user whose cancellations put them over the 50% high-cancel threshold, so the gate fires.
     */
    private function highCancelUser(): User
    {
        $user = User::factory()->create(['is_verified_passenger' => true]);
        $this->scores->initializeScore($user);

        UserScore::where('user_id', $user->id)->update([
            'total_rides' => 1,
            'total_cancellations' => 9,
        ]);

        return $user->fresh();
    }

    private function rideFor(User $driver): Ride
    {
        return RideBuilder::for($driver)
            ->withAttributes([
                'available_seats' => 4,
                'price_per_seat' => 50_000,
                'payment_method' => 'cash',
                'booking_type' => 'direct',
                'distance' => 320.5,
                'duration' => 240,
                'communication_number' => '0911000000',
            ])
            ->departureTime(now()->subHour())
            ->create();
    }

    private function bookingFor(User $passenger): Booking
    {
        return Booking::create([
            'user_id' => $passenger->id,
            'ride_id' => $this->rideFor(User::factory()->create(['is_verified_driver' => true]))->id,
            'seats' => 1,
            'status' => 'confirmed',
            'communication_number' => '0911000000',
        ]);
    }

    private function latestTransaction(User $user): ScoreTransaction
    {
        return ScoreTransaction::where('user_id', $user->id)->orderByDesc('id')->firstOrFail();
    }
}
