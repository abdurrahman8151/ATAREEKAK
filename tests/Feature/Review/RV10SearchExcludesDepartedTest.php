<?php

namespace Tests\Feature\Review;

use App\Enums\RideStatus;
use App\Models\Ride;
use App\Models\User;
use App\Services\Ride\RideSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * RV-10 — search must not surface a ride that has already departed.
 *
 * Root cause (proven): RideSearchService matched only `whereDate(departure_time, date)`
 * + status ACTIVE + seats. Nothing advances ACTIVE -> FINISHED outside the scheduler (whose
 * auto-confirm window is a product decision), so on the searched calendar day a ride whose
 * departure_time was hours ago stayed "available" in search indefinitely — a passenger
 * could keep finding and attempting to book rides that had already left.
 *
 * This pins the decision-free half of RV-10: the `departure_time >= now()` guard.
 * The departure-time BOOKING rule and the auto-complete scheduler are the coupled,
 * decision-gated half (the booking guard collides with the settlement fixtures that
 * deliberately book past-departure rides), recorded separately.
 *
 * Time is frozen (Carbon::setTestNow) so the "morning ride has departed / afternoon ride
 * has not" pair is deterministic regardless of wall-clock — the audit's own verify method.
 */
class RV10SearchExcludesDepartedTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ride(Carbon $departure): Ride
    {
        $driver = User::factory()->create(['is_verified_driver' => true]);

        // RV-25: the SAME real locations (Damascus 33.5138/36.2765, Aleppo 36.2021/37.1343),
        // now written POINT(lat lng) to match the corrected storage convention (MySQL applies
        // EPSG:4326 axis-order = lat first). Only the axis order changed; the endpoint matcher
        // still sits exactly on the pickup, and the only thing under test remains time.
        return RideBuilder::for($driver)
            ->rawPickup('POINT(33.5138 36.2765)')
            ->rawDestination('POINT(36.2021 37.1343)')
            ->withAttributes([
                'status' => RideStatus::ACTIVE->value,
                'available_seats' => 4,
                'payment_method' => 'cash',
                'departure_time' => $departure,
            ])
            ->create();
    }

    private function search(Carbon $today): Collection
    {
        return app(RideSearchService::class)->searchRides([
            'departure_date' => $today->toDateString(),
            'seats_required' => 1,
            'source_lat' => 33.5138, 'source_lng' => 36.2765,
            'dest_lat' => 36.2021, 'dest_lng' => 37.1343,
        ]);
    }

    /** @test */
    public function a_ride_that_already_departed_today_is_excluded_but_a_later_one_today_is_returned(): void
    {
        // Freeze at 12:00 today so "09:00 today" is unambiguously past and "15:00" future.
        $now = Carbon::today()->addHours(12);
        Carbon::setTestNow($now);
        $today = $now->copy();

        $departed = $this->ride(Carbon::today()->addHours(9));    // 09:00 < 12:00  -> gone
        $upcoming = $this->ride(Carbon::today()->addHours(15));   // 15:00 > 12:00  -> bookable

        $this->assertTrue($departed->departure_time->isPast());
        $this->assertTrue($upcoming->departure_time->isFuture());

        $ids = $this->search($today)->pluck('id')->all();

        $this->assertContains(
            $upcoming->id,
            $ids,
            'a not-yet-departed ride on the searched day must still be returned'
        );
        $this->assertNotContains(
            $departed->id,
            $ids,
            'RV-10: an already-departed ride must NOT be surfaced by search'
        );
    }

    /** @test */
    public function the_departed_and_upcoming_rides_differ_only_by_time_so_only_the_filter_can_exclude(): void
    {
        // Guards against the exclusion passing for the wrong reason (geometry/status/seats).
        // Both rides are identical ACTIVE/cash/4-seat at the same coordinates; the departed
        // one would be returned by the OLD query. Re-assert here that with the fix it is not,
        // and that the difference is purely departure_time.
        $now = Carbon::today()->addHours(12);
        Carbon::setTestNow($now);

        $departed = $this->ride(Carbon::today()->addHours(9));
        $upcoming = $this->ride(Carbon::today()->addHours(15));

        $this->assertSame($departed->status, $upcoming->status);
        $this->assertSame($departed->available_seats, $upcoming->available_seats);
        $this->assertTrue($departed->departure_time->lessThan($upcoming->departure_time));

        $ids = $this->search($now)->pluck('id')->all();
        $this->assertSame([$upcoming->id], $ids, 'only the future ride matches; both share every other field');
    }
}
