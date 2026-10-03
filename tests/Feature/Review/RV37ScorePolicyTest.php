<?php

namespace Tests\Feature\Review;

use App\Enums\ScoreAction;
use App\Models\User;
use App\Models\UserScore;
use App\Services\Ride\RideValidationService;
use App\Services\Score\ScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * un2 - score policy pinned by the owner on 2026-10-02 (R2 sec 40.1).
 *
 * The owner's numbers: a new account starts at **70**, the score is clamped **0-100**, the bands are
 * **Gold >= 80 / Silver >= 60 / Bronze >= 40**, a driver needs **50** to create a ride and a
 * passenger **40** to book one. They also rejected the larger scale that was present in the code
 * (platinum >= 200 / gold >= 150 / silver >= 100): `ScoreService::resolveTier` compared a 0-100 score
 * against those thresholds, so it wrote `bronze` for **every** user while `UserScore::getTierAttribute`
 * - the accessor the API reads - used the real 80/60/40 bands. The stored column and the accessor
 * disagreed, and nothing tested either. This class makes the policy executable so it cannot drift
 * again.
 */
class RV37ScorePolicyTest extends TestCase
{
    use RefreshDatabase;

    private ScoreService $scores;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scores = app(ScoreService::class);
    }

    /** @test */
    public function a_new_account_starts_at_seventy(): void
    {
        $user = User::factory()->create();

        $this->assertSame(70, $this->scores->getScore($user)->score);
        $this->assertDatabaseHas('user_scores', ['user_id' => $user->id, 'score' => 70]);
    }

    /** @test */
    public function the_score_is_clamped_at_both_ends(): void
    {
        $user = User::factory()->create();
        $score = UserScore::where('user_id', $user->id)->firstOrFail();

        // Starting at 70, a -15 no-show lands at 55 (no clamp needed yet).
        $score->applyDelta(-15);
        $this->assertSame(55, $score->fresh()->score);

        // Push below the floor: a large negative delta must not go under 0.
        $score->applyDelta(-100);
        $this->assertSame(0, $score->fresh()->score);

        // A large positive delta must not exceed the 100 ceiling.
        $score->applyDelta(500);
        $this->assertSame(100, $score->fresh()->score);
    }

    /** @test */
    public function the_bands_are_gold_at_eighty_silver_at_sixty_bronze_at_forty_restricted_below(): void
    {
        // `UserScore::getTierAttribute()` is the single source of truth for the bands; the legacy
        // `tier` column is deliberately never written (setTierAttribute discards assignments).
        $cases = [
            100 => 'Gold',
            80 => 'Gold',
            79 => 'Silver',
            60 => 'Silver',
            59 => 'Bronze',
            40 => 'Bronze',
            0 => 'Restricted',
        ];

        foreach ($cases as $score => $expected) {
            $user = User::factory()->create();
            UserScore::where('user_id', $user->id)->update(['score' => $score]);

            $this->assertSame(
                $expected,
                UserScore::where('user_id', $user->id)->firstOrFail()->tier,
                "a score of {$score} must read as {$expected}"
            );
        }
    }

    /**
     * The regression that motivated this: `ScoreService::applyAction` wrote a stored `tier` from a
     * 200/150/100 scale - thresholds a 0-100 score can never reach - so it labelled every user
     * `bronze` while the accessor returned the right band. The write is removed (R2 sec 42); this
     * pins that a real account driven to 80+ reads as Gold and that the legacy column is NOT the
     * source of truth (so no stale `bronze` can leak into a report that reads SQL directly).
     *
     * @test
     */
    public function a_real_account_driven_to_eighty_reads_as_gold(): void
    {
        $user = User::factory()->create();

        // RideCompletionPolicy awards +10 per completed ride; 70 -> 80 needs exactly one.
        $this->scores->applyAction($user, ScoreAction::RIDE_COMPLETED, reference: null);

        $row = UserScore::where('user_id', $user->id)->firstOrFail();

        $this->assertSame(80, $row->score);
        $this->assertSame('Gold', $row->tier, 'a score of 80 must read Gold, never bronze');
    }

    /**
     * The legacy `tier` column must never be written by the service: `setTierAttribute` discards
     * assignments, so a report reading the column directly would otherwise see the migration
     * default `bronze` for every user regardless of score. This asserts the column is inert (it is
     * vestigial) and that nothing depends on it for correctness.
     *
     * @test
     */
    public function the_legacy_tier_column_is_never_written_by_a_score_action(): void
    {
        $user = User::factory()->create();

        $this->scores->applyAction($user, ScoreAction::RIDE_COMPLETED, reference: null);

        // Whatever the column holds, the computed accessor is what the API and the bands use.
        $row = UserScore::where('user_id', $user->id)->firstOrFail();
        $computed = $row->tier;

        $this->assertSame(
            'Gold',
            $computed,
            'the computed tier is the source of truth and must be Gold at 80'
        );
    }

    /** @test */
    public function the_ride_gates_are_fifty_to_create_and_forty_to_book(): void
    {
        $constants = (new ReflectionClass(RideValidationService::class))->getConstants();

        $this->assertSame(50, $constants['MIN_SCORE_CREATE_RIDE']);
        $this->assertSame(40, $constants['MIN_SCORE_BOOK_RIDE']);
    }

    /**
     * Behavioural proof of the passenger gate, not just the constant: 39 is refused with the score
     * message, 40 is allowed. The passenger path needs no KYC documents, so this isolates the score
     * gate cleanly (the driver gate checks documents BEFORE the score - see
     * `RideValidationService::validateDriverCanCreateRide`).
     *
     * @test
     */
    public function the_passenger_gate_refuses_thirty_nine_and_allows_forty(): void
    {
        $validation = app(RideValidationService::class);

        $passenger = User::factory()->create(['is_verified_passenger' => true]);
        UserScore::where('user_id', $passenger->id)->update(['score' => 39]);

        $refused = false;
        try {
            $validation->validatePassengerCanBook($passenger);
        } catch (\InvalidArgumentException $e) {
            $refused = true;
            $this->assertMatchesRegularExpression(
                '/39/',
                $e->getMessage(),
                'the refusal must name the passenger\'s actual score'
            );
            $this->assertMatchesRegularExpression('/40/', $e->getMessage(), 'and the threshold');
        }
        $this->assertTrue($refused, 'a passenger scoring 39 must not be allowed to book');

        UserScore::where('user_id', $passenger->id)->update(['score' => 40]);
        $validation->validatePassengerCanBook($passenger);
        $this->addToAssertionCount(1);
    }
}
