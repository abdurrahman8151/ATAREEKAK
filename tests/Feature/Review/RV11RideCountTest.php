<?php

namespace Tests\Feature\Review;

use App\Enums\ScoreAction;
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
 * RV-11 - a completed ride counts ONCE, and the score policy is the one the owner pinned.
 *
 * R2 sec 67. Four defects, all found by reading the code rather than the audit prose:
 *
 *  1. `recordRideCompleted` called `applyAction(RIDE_COMPLETED)` AND THEN `incrementRides()`.
 *     `applyAction` already counts the ride, so every completed ride was counted TWICE.
 *  2. `applyAction`'s `firstOrCreate` started a user at 100 while the other three creation
 *     paths used 70 - the start score un2 pinned.
 *  3. `applyAction` clamped the floor at 0 but had NO ceiling, against the max of 100 that
 *     un2 pinned, while `UserScore::applyDelta` clamped both ends.
 *  4. `applyAction` wrote `cancel_rate`, which `setCancelRateAttribute` discards - dead code
 *     that also used a different formula from the accessor the policies actually read.
 *
 * WHY #1 MATTERS AND WHY NOBODY NOTICED. `cancel_rate` is not a stored value: the policies read
 * the computed accessor, `total_cancellations / (total_rides + total_cancellations)`. Doubling
 * `total_rides` inflates that denominator, so `cancel_rate` FALLS and the 50% high-cancel gate
 * quietly stops firing. Penalties under-apply - no user is charged more than they should be, which
 * is exactly why it stayed invisible. So the tests that matter are not only "does it double" but
 * "does the gate fire", and both are pinned here.
 */
class RV11RideCountTest extends TestCase
{
    use RefreshDatabase;

    private ScoreService $scores;

    private ?Ride $lastRide = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scores = app(ScoreService::class);
    }

    // -- 1. The double-count -------------------------------------------------------

    /**
     * THE core regression. One completed ride must move `total_rides` by exactly one, for the
     * driver AND for the passenger. Before the fix this asserted 1 and got 2 on both.
     *
     * @test
     */
    public function a_completed_ride_counts_once_for_the_driver_and_the_passenger(): void
    {
        [$driver, $passenger] = $this->completedRide();

        $this->assertSame(
            1,
            $this->ridesOf($driver),
            'a completed ride must count ONCE for the driver, not twice'
        );
        $this->assertSame(
            1,
            $this->ridesOf($passenger),
            'a completed ride must count ONCE for the passenger, not twice'
        );
    }

    /**
     * The defect scaled with rides, so it is pinned at 3 as well as at 1: an off-by-one per ride
     * is invisible on a single ride and doubles the denominator after a handful.
     *
     * @test
     */
    public function three_completed_rides_count_three(): void
    {
        [$driver, $passenger] = $this->completedRide();

        $this->scores->recordRideCompleted($this->lastRide);
        $this->scores->recordRideCompleted($this->lastRide);

        $this->assertSame(3, $this->ridesOf($driver));
        $this->assertSame(3, $this->ridesOf($passenger));
    }

    /**
     * A cancellation must NOT count as a ride. `applyAction` now gates the increment on the
     * action rather than on "the result happened to be positive", so a future positive non-ride
     * action cannot inflate the count either.
     *
     * @test
     */
    public function a_cancellation_does_not_count_as_a_ride(): void
    {
        $user = User::factory()->create();
        $this->scores->getScore($user);

        $this->scores->applyAction($user, ScoreAction::PASSENGER_CANCEL_MID, reference: null);
        $this->scores->applyAction($user, ScoreAction::PASSENGER_NO_SHOW, reference: null);

        $this->assertSame(0, $this->ridesOf($user), 'cancelling and no-showing are not rides');
    }

    // -- The consequence: the high-cancel gate must actually fire ---------------------

    /**
     * The behaviour the whole defect hid behind. A passenger who cancels repeatedly must cross
     * the 50% cancel-rate gate. With every ride double-counted the rate was diluted below the
     * threshold and the extra penalty never applied.
     *
     * The counters are set directly rather than driven through `recordPassengerCancel`, because
     * this is about the RATE the policy reads, not about the cancellation flow around it -
     * `applyAction` is the only path that decides whether the high-rate tier applies.
     *
     * @test
     */
    public function a_repeated_canceller_crosses_the_high_cancel_rate_gate(): void
    {
        $user = User::factory()->create();
        $this->setCounters($user, rides: 1, cancellations: 3);

        $this->assertSame(75.0, $this->cancelRateOf($user), 'sanity: 1 ride + 3 cancels is 75%');

        $this->scores->applyAction($user, ScoreAction::PASSENGER_CANCEL_EARLY, reference: null);

        $tx = $this->latestTransaction($user);
        $this->assertTrue(
            (bool) $tx->high_cancel_rate_applied,
            '75% is over the 50% gate, so the high-rate penalty must apply'
        );
        $this->assertSame(-10, (int) $tx->points, 'and it must carry the high-rate penalty');
    }

    /**
     * The gate must NOT fire for a well-behaved user - the other half, so a "fix" that simply
     * disabled the penalty cannot pass.
     *
     * @test
     */
    public function a_reliable_user_never_trips_the_gate(): void
    {
        $user = User::factory()->create();
        $this->setCounters($user, rides: 3, cancellations: 1);

        $this->assertSame(25.0, $this->cancelRateOf($user), 'sanity: 3 rides + 1 cancel is 25%');

        $this->scores->applyAction($user, ScoreAction::PASSENGER_CANCEL_EARLY, reference: null);

        $this->assertFalse(
            (bool) $this->latestTransaction($user)->high_cancel_rate_applied,
            '25% is below the 50% gate, so no high-rate penalty may apply'
        );
    }

    /**
     * The link that ties it together: the number of counted rides is what moves the rate across
     * the threshold. This is the defect restated as a business outcome - one extra ride per
     * completion is what pushed a chronic canceller back under the gate.
     *
     * @test
     */
    public function the_gate_moves_with_the_ride_count(): void
    {
        $canceller = User::factory()->create();
        $reliable = User::factory()->create();

        // Same cancellations, different rides counted.
        $this->setCounters($canceller, rides: 1, cancellations: 3);
        $this->setCounters($reliable, rides: 6, cancellations: 3);

        $this->assertGreaterThan(50.0, $this->cancelRateOf($canceller));
        $this->assertLessThan(50.0, $this->cancelRateOf($reliable));

        $this->scores->applyAction($canceller, ScoreAction::PASSENGER_CANCEL_EARLY, reference: null);
        $this->scores->applyAction($reliable, ScoreAction::PASSENGER_CANCEL_EARLY, reference: null);

        $this->assertTrue((bool) $this->latestTransaction($canceller)->high_cancel_rate_applied);
        $this->assertFalse((bool) $this->latestTransaction($reliable)->high_cancel_rate_applied);
    }

    // -- 2. The start score ---------------------------------------------------------

    /**
     * `applyAction` carried its own `firstOrCreate`, and it was the only creation path in the
     * codebase that started a user at 100 instead of the pinned 70.
     *
     * IMPORTANT, and this test makes the distinction explicit: `UserObserver` calls
     * `initializeScore` on `created`, so in normal operation a score row already exists and the
     * 100 default is NEVER reached. It was a LATENT inconsistency, not one that had been handing
     * users a wrong score. It would still bite for any row that predates the observer, or if the
     * observer's own `initializeScore` failed. This test reproduces exactly that case - the score
     * row removed - so the fallback is pinned without pretending it fires on every signup.
     *
     * A no-show is used rather than a ride completion because it is negative, so it also proves
     * the start score without moving `total_rides`.
     *
     * @test
     */
    public function apply_action_falls_back_to_the_pinned_start_score_when_no_row_exists(): void
    {
        $user = User::factory()->create();
        $this->assertDatabaseHas('user_scores', ['user_id' => $user->id]);

        // Simulate a legacy user (or a failed observer run): no score row at all.
        UserScore::where('user_id', $user->id)->delete();
        $this->assertDatabaseMissing('user_scores', ['user_id' => $user->id]);

        $this->scores->applyAction($user, ScoreAction::PASSENGER_NO_SHOW, reference: null);

        $this->assertSame(
            55,
            $this->scoreOf($user),
            '-15 from the pinned start of 70 gives 55; from 100 it would be 85'
        );
    }

    /**
     * @test
     */
    public function the_start_score_is_the_same_from_every_creation_path(): void
    {
        foreach (['initializeScore', 'getScore'] as $method) {
            $user = User::factory()->create();
            $this->scores->{$method}($user);

            $this->assertSame(
                ScoreService::START_SCORE,
                $this->scoreOf($user),
                "{$method}() must start a user at the pinned start score"
            );
        }
    }

    // -- 3. The ceiling -------------------------------------------------------------

    /**
     * The ceiling was missing on the `applyAction` path only, so a run of positive actions could
     * push a user past their maximum while `applyDelta` could not.
     *
     * @test
     */
    public function apply_action_respects_the_ceiling(): void
    {
        $user = User::factory()->create();
        $this->scores->getScore($user);

        for ($i = 0; $i < 10; $i++) {
            $this->scores->applyAction($user, ScoreAction::RIDE_COMPLETED, reference: null);
        }

        $this->assertSame(
            ScoreService::MAX_SCORE,
            $this->scoreOf($user),
            'a score must never exceed the pinned maximum of 100'
        );
    }

    /**
     * @test
     */
    public function apply_action_respects_the_floor(): void
    {
        $user = User::factory()->create();
        $this->scores->getScore($user);

        for ($i = 0; $i < 10; $i++) {
            $this->scores->applyAction($user, ScoreAction::DRIVER_NO_SHOW, reference: null);
        }

        $this->assertSame(ScoreService::MIN_SCORE, $this->scoreOf($user));
    }

    /**
     * Both mutation paths must agree on the bounds, which is what the fix restored: `applyDelta`
     * always clamped both ends, `applyAction` clamped the floor only.
     *
     * @test
     */
    public function both_mutation_paths_clamp_identically(): void
    {
        $user = User::factory()->create();
        $row = $this->scores->getScore($user);

        $row->applyDelta(500);
        $this->assertSame(ScoreService::MAX_SCORE, $row->fresh()->score, 'applyDelta ceiling');

        $row->fresh()->applyDelta(-500);
        $this->assertSame(ScoreService::MIN_SCORE, $row->fresh()->score, 'applyDelta floor');
    }

    // -- 4. cancel_rate is computed, never stored ------------------------------------

    /**
     * The dead write is gone. Assigning it is provably a no-op, so if the column is ever written
     * again it would mean the model changed - and this test fails loudly instead of the model
     * silently disagreeing with the accessor the policies read.
     *
     * @test
     */
    public function cancel_rate_is_computed_and_a_write_is_still_discarded(): void
    {
        $user = User::factory()->create();
        $row = $this->scores->getScore($user);
        $row->update(['total_rides' => 10, 'total_cancellations' => 0]);

        $fresh = $row->fresh();
        $fresh->cancel_rate = 99.0;
        $fresh->save();

        $this->assertSame(
            0.0,
            $this->cancelRateOf($user),
            'cancel_rate is computed from the counters, so a write must not stick'
        );
        $this->assertDatabaseHas('user_scores', ['user_id' => $user->id, 'total_rides' => 10]);
    }

    // -- Helpers --------------------------------------------------------------------

    private function setCounters(User $user, int $rides, int $cancellations): void
    {
        $this->scores->getScore($user);
        UserScore::where('user_id', $user->id)->update([
            'total_rides' => $rides,
            'total_cancellations' => $cancellations,
        ]);
    }

    private function latestTransaction(User $user): ScoreTransaction
    {
        return ScoreTransaction::where('user_id', $user->id)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function completedRide(): array
    {
        $driver = User::factory()->create(['is_verified_driver' => true]);
        $passenger = User::factory()->create(['is_verified_passenger' => true]);

        // The shared RV-34 builder, because `Ride::factory()` writes geometry with DB::raw and
        // `setPickupLocationAttribute` rejects an Expression. Same reason the rest of the suite
        // uses it - not a workaround invented for this test.
        $ride = RideBuilder::for($driver)
            ->withAttributes([
                'available_seats' => 4,
                'price_per_seat' => 50000,
                'payment_method' => 'cash',
                'booking_type' => 'direct',
                'distance' => 320.5,
                'duration' => 240,
                'communication_number' => '0911000000',
            ])
            ->status('completed')
            ->departureTime(now()->subHour())
            ->create();

        // No BookingFactory exists in this project, so `Booking::create` is the suite's pattern.
        Booking::create([
            'user_id' => $passenger->id,
            'ride_id' => $ride->id,
            'seats' => 1,
            'status' => 'completed',
            'communication_number' => '0911000000',
        ]);

        $this->scores->recordRideCompleted($ride);
        $this->lastRide = $ride;

        return [$driver, $passenger];
    }

    private function ridesOf(User $user): int
    {
        return (int) UserScore::where('user_id', $user->id)->value('total_rides');
    }

    private function scoreOf(User $user): int
    {
        return (int) UserScore::where('user_id', $user->id)->value('score');
    }

    private function cancelRateOf(User $user): float
    {
        return (float) UserScore::where('user_id', $user->id)->firstOrFail()->cancel_rate;
    }
}
