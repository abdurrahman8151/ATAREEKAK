<?php

namespace Tests\Unit\Domain;

use App\Domain\Score\Policies\DriverNoShowPolicy;
use App\Domain\Score\Policies\ScorePolicyInterface;
use App\Domain\Score\ScoreResult;
use App\Enums\ScoreAction;
use App\Models\UserScore;
use PHPUnit\Framework\TestCase;

class DriverNoShowPolicyTest extends TestCase
{
    private DriverNoShowPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new DriverNoShowPolicy;
    }

    // ─── supports() ───────────────────────────────────────────────────────────

    public function test_supports_driver_no_show_action(): void
    {
        $this->assertTrue($this->policy->supports(ScoreAction::DRIVER_NO_SHOW));
    }

    public function test_does_not_support_ride_completed(): void
    {
        $this->assertFalse($this->policy->supports(ScoreAction::RIDE_COMPLETED));
    }

    public function test_does_not_support_driver_cancel_ride_early(): void
    {
        $this->assertFalse($this->policy->supports(ScoreAction::DRIVER_CANCEL_RIDE_EARLY));
    }

    public function test_does_not_support_driver_cancel_ride_mid(): void
    {
        $this->assertFalse($this->policy->supports(ScoreAction::DRIVER_CANCEL_RIDE_MID));
    }

    public function test_does_not_support_driver_cancel_ride_late(): void
    {
        $this->assertFalse($this->policy->supports(ScoreAction::DRIVER_CANCEL_RIDE_LATE));
    }

    public function test_does_not_support_driver_cancel_seat(): void
    {
        $this->assertFalse($this->policy->supports(ScoreAction::DRIVER_CANCEL_SEAT));
    }

    public function test_does_not_support_passenger_no_show(): void
    {
        $this->assertFalse($this->policy->supports(ScoreAction::PASSENGER_NO_SHOW));
    }

    public function test_does_not_support_passenger_cancel_early(): void
    {
        $this->assertFalse($this->policy->supports(ScoreAction::PASSENGER_CANCEL_EARLY));
    }

    // ─── calculate() ──────────────────────────────────────────────────────────

    public function test_calculate_returns_score_result_instance(): void
    {
        $result = $this->policy->calculate(ScoreAction::DRIVER_NO_SHOW, $this->makeScore());
        $this->assertInstanceOf(ScoreResult::class, $result);
    }

    public function test_calculate_returns_negative_fifteen_points(): void
    {
        $result = $this->policy->calculate(ScoreAction::DRIVER_NO_SHOW, $this->makeScore());
        $this->assertEquals(-15, $result->points);
    }

    public function test_calculate_result_is_negative(): void
    {
        $result = $this->policy->calculate(ScoreAction::DRIVER_NO_SHOW, $this->makeScore());
        $this->assertTrue($result->isNegative());
        $this->assertFalse($result->isPositive());
        $this->assertFalse($result->isNeutral());
    }

    public function test_calculate_reason_mentions_no_show(): void
    {
        $result = $this->policy->calculate(ScoreAction::DRIVER_NO_SHOW, $this->makeScore());
        $this->assertStringContainsString('no-show', strtolower($result->reason));
    }

    public function test_calculate_sets_correct_action_on_result(): void
    {
        $result = $this->policy->calculate(ScoreAction::DRIVER_NO_SHOW, $this->makeScore());
        $this->assertEquals(ScoreAction::DRIVER_NO_SHOW, $result->action);
    }

    public function test_high_cancel_rate_does_not_override_no_show_penalty(): void
    {
        // No-show is always fixed at -15; cancel rate has no effect.
        $highRate = new UserScore([
            'score' => 70,
            'total_rides' => 5,
            'total_cancellations' => 5,  // 50% cancel rate
        ]);

        $result = $this->policy->calculate(ScoreAction::DRIVER_NO_SHOW, $highRate);

        $this->assertEquals(-15, $result->points);
        $this->assertFalse($result->highCancelRateApplied);
    }

    public function test_penalty_is_identical_regardless_of_cancel_rate(): void
    {
        $low = new UserScore(['score' => 70, 'total_rides' => 10, 'total_cancellations' => 0]);
        $high = new UserScore(['score' => 70, 'total_rides' => 10, 'total_cancellations' => 9]);

        $r1 = $this->policy->calculate(ScoreAction::DRIVER_NO_SHOW, $low);
        $r2 = $this->policy->calculate(ScoreAction::DRIVER_NO_SHOW, $high);

        $this->assertEquals($r1->points, $r2->points);
    }

    public function test_penalty_is_same_regardless_of_context_data(): void
    {
        $score = $this->makeScore();

        $r1 = $this->policy->calculate(ScoreAction::DRIVER_NO_SHOW, $score, []);
        $r2 = $this->policy->calculate(ScoreAction::DRIVER_NO_SHOW, $score, ['elapsed_pct' => 50]);
        $r3 = $this->policy->calculate(ScoreAction::DRIVER_NO_SHOW, $score, ['extra' => 'data']);

        $this->assertEquals(-15, $r1->points);
        $this->assertEquals(-15, $r2->points);
        $this->assertEquals(-15, $r3->points);
    }

    public function test_policy_implements_score_policy_interface(): void
    {
        $this->assertInstanceOf(ScorePolicyInterface::class, $this->policy);
    }

    public function test_result_to_array_has_expected_keys(): void
    {
        $result = $this->policy->calculate(ScoreAction::DRIVER_NO_SHOW, $this->makeScore());
        $array = $result->toArray();

        $this->assertArrayHasKey('points', $array);
        $this->assertArrayHasKey('action', $array);
        $this->assertArrayHasKey('reason', $array);
        $this->assertArrayHasKey('high_cancel_rate_applied', $array);
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    private function makeScore(int $rides = 5, int $cancellations = 1): UserScore
    {
        return new UserScore([
            'score' => 70,
            'total_rides' => $rides,
            'total_cancellations' => $cancellations,
        ]);
    }
}
