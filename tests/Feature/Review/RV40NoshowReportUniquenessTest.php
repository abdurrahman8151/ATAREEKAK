<?php

namespace Tests\Feature\Review;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * RV-40 — the database refuses a second no-show report for the same booking/reporter.
 *
 * §26.13 records the reasoning for the backstop: the service already blocks live
 * duplicates (status-scoped guard, the confirmed-booking precondition, and lockForUpdate
 * on the booking), so this index changes no reachable behaviour. What it closes is the
 * writer that bypasses the service — and report resolution releases escrow to the
 * reporter, so a duplicate row is a double-release hazard, not a cosmetic one.
 *
 * This deliberately exercises the DENIED path rather than only asserting the index exists
 * in information_schema (which MigrationEffectsBatchTest already pins). A constraint whose
 * presence is asserted but whose rejection is never exercised is exactly the kind of test
 * that passes while enforcing nothing.
 */
class RV40NoshowReportUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private int $rideId;

    private int $bookingId;

    private int $driverId;

    private int $passengerId;

    protected function setUp(): void
    {
        parent::setUp();

        $driver = User::factory()->create(['is_verified_driver' => true]);
        $passenger = User::factory()->create(['is_verified_passenger' => true]);

        $ride = RideBuilder::for($driver)
            ->rawPickup('POINT(36.2765 33.5138)')
            ->rawDestination('POINT(37.1343 36.2021)')
            ->withAttributes(['status' => 'active', 'available_seats' => 4])
            ->create();

        $booking = Booking::create([
            'ride_id' => $ride->id,
            'user_id' => $passenger->id,
            'seats' => 1,
            'status' => BookingStatus::CONFIRMED->value,
        ]);

        $this->rideId = (int) $ride->id;
        $this->bookingId = (int) $booking->id;
        $this->driverId = (int) $driver->id;
        $this->passengerId = (int) $passenger->id;
    }

    /** @return array<string,mixed> */
    private function reportRow(): array
    {
        return [
            'ride_id' => $this->rideId,
            'booking_id' => $this->bookingId,
            'reporter_id' => $this->driverId,
            'reporter_role' => 'driver',
            'target_id' => $this->passengerId,
            'target_role' => 'passenger',
            'payment_method' => 'cash',
            'status' => 'pending',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @test */
    public function a_second_report_for_the_same_booking_and_reporter_is_rejected(): void
    {
        // ALLOWED path: the first report is accepted (control for the denied case below).
        DB::table('noshow_reports')->insert($this->reportRow());
        $this->assertSame(
            1,
            DB::table('noshow_reports')->where('booking_id', $this->bookingId)->count(),
            'the first report must be accepted'
        );

        // DENIED path: identical (booking_id, reporter_id) must be refused by the index.
        $threw = false;
        try {
            DB::table('noshow_reports')->insert($this->reportRow());
        } catch (QueryException $e) {
            $threw = true;
            $this->assertStringContainsString(
                'uq_noshow_report_booking_reporter',
                $e->getMessage(),
                'the rejection must come from the named unique index'
            );
        }

        $this->assertTrue($threw, 'a duplicate (booking_id, reporter_id) must be rejected');
        $this->assertSame(
            1,
            DB::table('noshow_reports')->where('booking_id', $this->bookingId)->count(),
            'the duplicate must not have been stored'
        );
    }

    /** @test */
    public function a_different_reporter_for_the_same_booking_is_still_allowed(): void
    {
        // The constraint must not over-reach: staff or the passenger reporting against
        // the same booking is a different reporter and must remain possible.
        DB::table('noshow_reports')->insert($this->reportRow());

        $other = User::factory()->create();

        $second = $this->reportRow();
        $second['reporter_id'] = (int) $other->id;
        $second['reporter_role'] = 'passenger';
        $second['target_id'] = $this->driverId;
        $second['target_role'] = 'driver';

        DB::table('noshow_reports')->insert($second);

        $this->assertSame(
            2,
            DB::table('noshow_reports')->where('booking_id', $this->bookingId)->count(),
            'a different reporter on the same booking must still be storable'
        );
    }
}
