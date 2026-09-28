<?php

namespace Tests\Unit\Services;

use App\Models\Photo;
use App\Models\User;
use App\Services\Ride\RideValidationService;
use App\Services\Verification\DocumentVerificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WHY THIS EXTENDS Laravel TestCase (not PHPUnit):
 * DocumentVerificationService is marked `final` — Mockery cannot mock it.
 * We use the REAL service instead. Since validateDriverDocuments() queries
 * the `photos` table, we need a real DB — hence RefreshDatabase.
 */
class RideValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    private RideValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RideValidationService(new DocumentVerificationService);
    }

    // ── validateDepartureTime ────────────────────────────────────────────────

    public function test_departure_time_in_future_is_valid(): void
    {
        $this->expectNotToPerformAssertions();
        $this->service->validateDepartureTime(Carbon::now()->addHour());
    }

    public function test_departure_time_in_past_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->service->validateDepartureTime(Carbon::now()->subMinutes(10));
    }

    public function test_departure_time_less_than_5_min_ahead_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->service->validateDepartureTime(Carbon::now()->addMinutes(3));
    }

    public function test_departure_time_more_than_30_days_ahead_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->service->validateDepartureTime(Carbon::now()->addDays(31));
    }

    // ── validateSeatsAvailable ───────────────────────────────────────────────

    public function test_valid_seat_request(): void
    {
        $this->expectNotToPerformAssertions();
        $this->service->validateSeatsAvailable(2, 4);
    }

    public function test_requesting_exact_available_seats_is_valid(): void
    {
        $this->expectNotToPerformAssertions();
        $this->service->validateSeatsAvailable(4, 4);
    }

    public function test_requesting_more_seats_than_available_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->service->validateSeatsAvailable(5, 4);
    }

    public function test_requesting_zero_seats_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->service->validateSeatsAvailable(0, 4);
    }

    public function test_requesting_more_than_8_seats_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->service->validateSeatsAvailable(9, 10);
    }

    // ── validateCanCancelRide ────────────────────────────────────────────────

    public function test_can_cancel_ride_when_2_hours_before_departure(): void
    {
        $this->expectNotToPerformAssertions();
        $this->service->validateCanCancelRide(Carbon::now()->addHours(2)->addMinutes(1));
    }

    public function test_cannot_cancel_ride_less_than_1_hour_before_departure(): void
    {
        $this->expectException(\Exception::class);
        $this->service->validateCanCancelRide(Carbon::now()->addMinutes(30));
    }

    // ── validateDriverCanCreateRide ──────────────────────────────────────────

    public function test_unverified_driver_cannot_create_ride(): void
    {
        $driver = User::factory()->create(['is_verified_driver' => false]);

        $this->expectException(\Exception::class);
        $this->service->validateDriverCanCreateRide($driver);
    }

    public function test_driver_without_profile_cannot_create_ride(): void
    {
        $driver = User::factory()->create(['is_verified_driver' => true]);
        $driver->profile()->delete();

        $this->expectException(\Exception::class);
        $this->service->validateDriverCanCreateRide($driver->fresh());
    }

    public function test_verified_driver_with_all_documents_can_create_ride(): void
    {
        $driver = User::factory()->create(['is_verified_driver' => true]);

        if (! $driver->profile) {
            $driver->profile()->create(['full_name' => 'Driver', 'number_of_rides' => 0]);
        }

        foreach (['face_id', 'back_id', 'license', 'mechanic_card'] as $type) {
            Photo::create(['user_id' => $driver->id, 'type' => $type, 'path' => "test/{$type}.jpg"]);
        }

        $this->expectNotToPerformAssertions();
        $this->service->validateDriverCanCreateRide($driver->fresh());
    }

    public function test_driver_with_missing_documents_cannot_create_ride(): void
    {
        $driver = User::factory()->create(['is_verified_driver' => true]);

        if (! $driver->profile) {
            $driver->profile()->create(['full_name' => 'Driver', 'number_of_rides' => 0]);
        }

        // Only face_id — missing back_id, license, mechanic_card
        Photo::create(['user_id' => $driver->id, 'type' => 'face_id', 'path' => 'test/face.jpg']);

        $this->expectException(\Exception::class);
        $this->service->validateDriverCanCreateRide($driver->fresh());
    }

    // ── validatePassengerCanBook ──────────────────────────────────────────────

    public function test_verified_passenger_can_book(): void
    {
        $passenger = User::factory()->create(['is_verified_passenger' => true]);

        $this->expectNotToPerformAssertions();
        $this->service->validatePassengerCanBook($passenger);
    }

    public function test_unverified_passenger_cannot_book(): void
    {
        $passenger = User::factory()->create(['is_verified_passenger' => false]);

        $this->expectException(\Exception::class);
        $this->service->validatePassengerCanBook($passenger);
    }
}
