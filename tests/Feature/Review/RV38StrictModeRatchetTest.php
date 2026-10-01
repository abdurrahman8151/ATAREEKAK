<?php

namespace Tests\Feature\Review;

use App\Models\Booking;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

/**
 * RV-38 ratchet — Eloquent data-integrity strictness must stay ON outside production,
 * and must actually be LIVE (not merely configured).
 *
 * WHY this test exists (not just a config check): RV-38's whole value is turning SILENT
 * DATA LOSS into a loud error — `Model::create([... 'not_fillable' => x])` currently drops
 * the value with zero signal, and this session found several such bugs that way (RV-23's
 * complaint ride_id, RV-38's own measurement surfacing PushTokenManager silently stopping
 * token reassignment). Two distinct failure modes must both be prevented by this pin:
 *
 *   (a) someone deletes/comments the enable in AppServiceProvider::boot()  -> flag false
 *   (b) the enable is present but the guard never actually fires           -> no throw
 *
 * So we assert the configured flag AND perform a real discarded-attribute write and expect
 * MassAssignmentException. (a) alone would pass a config-only assertion; (b) alone would
 * pass a "is it on" check. Together they make the ratchet falsifiable both ways.
 *
 * NOT covered deliberately: preventLazyLoading(). That is RV-24 (N+1) territory and, per
 * the RV-38 measurement, would surface a large performance-driven blast radius rather than
 * data integrity; adopting it here would be scope creep. This pins only the two
 * data-integrity flags that are demonstrably clean across the full suite.
 */
class RV38StrictModeRatchetTest extends TestCase
{
    /** @test */
    public function discard_and_missing_prevention_are_enabled_outside_production(): void
    {
        // The test environment is never "production", so the enable in boot() applies.
        $this->assertFalse(app()->isProduction(),
            'precondition: this suite runs in a non-production env where the flags apply');

        $this->assertTrue(
            Model::preventsSilentlyDiscardingAttributes(),
            'RV-38: preventSilentlyDiscardingAttributes() must be ON in non-production — '
            .'a commented-out/removed enable is exactly the regression this pins.'
        );

        $this->assertTrue(
            Model::preventsAccessingMissingAttributes(),
            'RV-38: preventAccessingMissingAttributes() must be ON in non-production.'
        );
    }

    /** @test */
    public function a_discarded_attribute_write_actually_throws(): void
    {
        // Live proof the guard is enforced, not just configured: mass-assign a key that is
        // neither fillable nor a real column. Before RV-38 this was DROPPED IN SILENCE; now
        // it must raise. Booking has a defined $fillable, so a foreign key trips it.
        // (No DB write: fill() throws before any insert.)
        $booking = new Booking;

        $this->expectException(MassAssignmentException::class);

        // 'passenger_phone' was one of the phantom keys RV-38's measurement found being
        // silently discarded in UntangleBatchTest — a real, historically-dropped key.
        $booking->fill(['passenger_phone' => '0912345678']);
    }

    /** @test */
    public function the_guard_is_a_hard_exception_not_a_silent_drop(): void
    {
        // Complements the above: proves fill() DID throw (so the value was refused, not
        // quietly ignored). If the enable were absent, fill() would return the model with
        // the key dropped and no exception — this asserts the refusal is loud.
        $booking = new Booking;
        $thrown = false;
        try {
            $booking->fill(['totally_unknown_column' => 1]);
        } catch (MassAssignmentException $e) {
            $thrown = true;
            $this->assertStringContainsString('totally_unknown_column', $e->getMessage(),
                'the exception must name the offending key so the bug is actionable');
        }

        $this->assertTrue($thrown,
            'RV-38: a discarded attribute must raise MassAssignmentException, not drop silently');
    }
}
