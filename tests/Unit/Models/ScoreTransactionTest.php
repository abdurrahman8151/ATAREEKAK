<?php

namespace Tests\Unit\Models;

use App\Models\ScoreTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ScoreTransactionTest — Unit tests for the ScoreTransaction model.
 *
 * COVERS:
 *   - Fillable attributes
 *   - Casts (integer, boolean, array)
 *   - UPDATED_AT = null  (insert-only, no updated_at column)
 *   - user() BelongsTo relationship
 *   - is_positive accessor
 *   - formatted_points accessor
 *   - Database persistence and metadata round-trip
 */
class ScoreTransactionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    // ─── Fillable ─────────────────────────────────────────────────────────────

    public function test_fillable_contains_all_expected_fields(): void
    {
        $fillable = (new ScoreTransaction)->getFillable();

        foreach ([
            'user_id', 'action', 'points', 'previous_score', 'new_score',
            'reference_type', 'reference_id', 'reason',
            'high_cancel_rate_applied', 'metadata',
        ] as $field) {
            $this->assertContains($field, $fillable, "Expected '{$field}' to be fillable");
        }
    }

    // ─── Casts ────────────────────────────────────────────────────────────────

    public function test_points_is_cast_to_integer(): void
    {
        $this->assertEquals('integer', (new ScoreTransaction)->getCasts()['points']);
    }

    public function test_previous_score_is_cast_to_integer(): void
    {
        $this->assertEquals('integer', (new ScoreTransaction)->getCasts()['previous_score']);
    }

    public function test_new_score_is_cast_to_integer(): void
    {
        $this->assertEquals('integer', (new ScoreTransaction)->getCasts()['new_score']);
    }

    public function test_high_cancel_rate_applied_is_cast_to_boolean(): void
    {
        $this->assertEquals('boolean', (new ScoreTransaction)->getCasts()['high_cancel_rate_applied']);
    }

    public function test_metadata_is_cast_to_array(): void
    {
        $this->assertEquals('array', (new ScoreTransaction)->getCasts()['metadata']);
    }

    public function test_updated_at_constant_is_null(): void
    {
        // Insert-only model — Eloquent must not write an updated_at column
        $this->assertNull(ScoreTransaction::UPDATED_AT);
    }

    // ─── Relationship ─────────────────────────────────────────────────────────

    public function test_belongs_to_user(): void
    {
        $tx = $this->makeTx(10);

        $this->assertNotNull($tx->user);
        $this->assertEquals($this->user->id, $tx->user->id);
    }

    // ─── is_positive accessor ─────────────────────────────────────────────────

    public function test_is_positive_returns_true_for_positive_points(): void
    {
        $this->assertTrue((new ScoreTransaction(['points' => 10]))->is_positive);
    }

    public function test_is_positive_returns_false_for_negative_points(): void
    {
        $this->assertFalse((new ScoreTransaction(['points' => -5]))->is_positive);
    }

    public function test_is_positive_returns_false_for_zero_points(): void
    {
        $this->assertFalse((new ScoreTransaction(['points' => 0]))->is_positive);
    }

    // ─── formatted_points accessor ────────────────────────────────────────────

    public function test_formatted_points_prefixes_positive_with_plus(): void
    {
        $this->assertEquals('+10', (new ScoreTransaction(['points' => 10]))->formatted_points);
    }

    public function test_formatted_points_prefixes_zero_with_plus(): void
    {
        $this->assertEquals('+0', (new ScoreTransaction(['points' => 0]))->formatted_points);
    }

    public function test_formatted_points_preserves_minus_for_negative(): void
    {
        $this->assertEquals('-7', (new ScoreTransaction(['points' => -7]))->formatted_points);
    }

    public function test_formatted_points_returns_string(): void
    {
        $this->assertIsString((new ScoreTransaction(['points' => 10]))->formatted_points);
    }

    // ─── Persistence ──────────────────────────────────────────────────────────

    public function test_transaction_is_persisted_to_database(): void
    {
        $this->makeTx(10, 'ride_completed');

        $this->assertDatabaseHas('score_transactions', [
            'user_id' => $this->user->id,
            'action' => 'ride_completed',
            'points' => 10,
        ]);
    }

    public function test_metadata_is_stored_and_retrieved_as_array(): void
    {
        $meta = ['ride_id' => 99, 'booking_id' => 42];
        $tx = $this->makeTx(10, metadata: $meta);

        $fresh = $tx->fresh();
        $this->assertIsArray($fresh->metadata);
        $this->assertEquals(99, $fresh->metadata['ride_id']);
    }

    public function test_high_cancel_rate_applied_defaults_to_false(): void
    {
        $tx = $this->makeTx(10);
        $this->assertFalse((bool) $tx->fresh()->high_cancel_rate_applied);
    }

    public function test_high_cancel_rate_applied_can_be_set_to_true(): void
    {
        $tx = $this->makeTx(-15, 'driver_no_show', highRate: true);
        $this->assertTrue((bool) $tx->fresh()->high_cancel_rate_applied);
    }

    public function test_reference_type_and_id_are_nullable(): void
    {
        $tx = $this->makeTx(10);
        $this->assertNull($tx->fresh()->reference_type);
        $this->assertNull($tx->fresh()->reference_id);
    }

    public function test_multiple_transactions_can_exist_for_same_user(): void
    {
        $this->makeTx(10);
        $this->makeTx(-5);

        $this->assertEquals(
            2,
            ScoreTransaction::where('user_id', $this->user->id)->count()
        );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeTx(
        int $points,
        string $action = 'ride_completed',
        bool $highRate = false,
        ?array $metadata = null,
    ): ScoreTransaction {
        return ScoreTransaction::create([
            'user_id' => $this->user->id,
            'action' => $action,
            'points' => $points,
            'previous_score' => 70,
            'new_score' => 70 + $points,
            'reason' => "Test reason for {$action}",
            'high_cancel_rate_applied' => $highRate,
            'metadata' => $metadata,
        ]);
    }
}
