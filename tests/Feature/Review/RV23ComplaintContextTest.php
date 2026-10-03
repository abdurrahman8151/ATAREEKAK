<?php

namespace Tests\Feature\Review;

use App\Enums\BookingStatus;
use App\Enums\ComplaintStatus;
use App\Enums\ComplaintType;
use App\Enums\RideStatus;
use App\Models\Booking;
use App\Models\Complaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * RV-23 (slice 1) — a no-show conflict complaint must KEEP its ride and respondent.
 *
 * Root cause: Noshowservice::handleConflict writes Complaint::create with 'ride_id' and
 * 'complained_id', but neither column existed and neither key was fillable, so Eloquent
 * silently dropped both. The complaint auto-opened when driver AND passenger both press
 * the no-show button — the case support needs context for MOST — arrived unattributed
 * with no ride link, while the code kept writing the fields as if they worked. Silent
 * data loss (the same class RV-38 exists to surface).
 *
 * The payload below is copied field-for-field from the real write site so the test
 * cannot pass on a shape production never uses.
 */
class RV23ComplaintContextTest extends TestCase
{
    use RefreshDatabase;

    private function conflictFixture(): array
    {
        $driver = User::factory()->create(['is_verified_driver' => true]);
        $passenger = User::factory()->create(['is_verified_passenger' => true]);

        $ride = RideBuilder::for($driver)
            ->rawPickup('POINT(36.2765 33.5138)')
            ->rawDestination('POINT(37.1343 36.2021)')
            ->withAttributes(['status' => RideStatus::ACTIVE->value, 'available_seats' => 4])
            ->create();

        $booking = Booking::create([
            'ride_id' => $ride->id,
            'user_id' => $passenger->id,
            'seats' => 1,
            'status' => BookingStatus::CONFIRMED->value,
        ]);

        return [$driver, $passenger, $ride, $booking];
    }

    /** @test */
    public function the_noshow_conflict_payload_persists_ride_and_respondent(): void
    {
        [$driver, $passenger, $ride, $booking] = $this->conflictFixture();

        // Exactly the array Noshowservice::handleConflict writes (L461-469).
        $complaint = Complaint::create([
            'user_id' => $passenger->id,
            'complained_id' => $driver->id,
            'type' => ComplaintType::NO_SHOW->value,
            'title' => 'تعارض تقارير الغياب — يحتاج تحقيقاً',
            'description' => 'conflict body',
            'status' => ComplaintStatus::PENDING->value,
            'ride_id' => $ride->id,
        ]);

        $fresh = $complaint->fresh();

        $this->assertNotNull($fresh->ride_id,
            'RV-23: ride_id was silently dropped before the fix');
        $this->assertSame($ride->id, (int) $fresh->ride_id);
        $this->assertNotNull($fresh->complained_id,
            'RV-23: complained_id was silently dropped before the fix');
        $this->assertSame($driver->id, (int) $fresh->complained_id);

        // The relations actually resolve, not just the raw ids.
        // RV-37 / un9: eager-load both - reading them off a bare fresh() is a lazy load, which the
        // armed lazy guard turns into a violation.
        $fresh->load(['ride', 'complainedUser']);
        $this->assertTrue($fresh->ride->is($ride));
        $this->assertTrue($fresh->complainedUser->is($driver));
    }

    /** @test */
    public function manual_complaints_without_ride_context_are_still_valid(): void
    {
        // Additive/nullable guarantee: ComplaintService::submit posts NO ride_id /
        // complained_id at all — those rows must keep working, not hit a NOT NULL.
        $user = User::factory()->create();

        $complaint = Complaint::create([
            'user_id' => $user->id,
            'assigned_to' => null,
            'title' => 'pricing issue',
            'description' => 'body',
            'type' => ComplaintType::FINANCIAL_ISSUE->value,
            'status' => ComplaintStatus::PENDING->value,
        ]);

        $this->assertNull($complaint->fresh()->ride_id);
        $this->assertNull($complaint->fresh()->complained_id);
    }
}
