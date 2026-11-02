<?php

namespace Tests\Feature\Review;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Modules\Ride\Services\ScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Concerns\ActsAsStaff;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * Stage 0.6 item 2 - the VALUES behind `GET /api/score/transactions`' reference blocks.
 *
 * The corpus case (`score/transactions-with-ride-and-booking-references`) pins that the endpoint
 * answers 200 instead of 500 and that the reference blocks exist with the right KEYS. It cannot pin
 * the VALUES: `reference` is one of the normaliser's ID keys, so every scalar inside it - including
 * `origin` and `destination` - is aliased to `<id:N>`. A mapping that silently swapped origin for
 * destination, or read `destination_address` twice, would still produce a byte-identical snapshot.
 *
 * This file asserts the real text against the rows it came from, which is why it exists next to the
 * snapshot rather than instead of it.
 */
final class ScoreTransactionReferenceValuesTest extends TestCase
{
    use ActsAsStaff;
    use RefreshDatabase;

    #[Test]
    public function a_ride_reference_carries_the_ride_route_and_not_an_invented_attribute(): void
    {
        [$passenger, $ride] = $this->world();

        app(ScoreService::class)->recordRideCompleted($ride);

        $reference = $this->referenceFor($passenger, 'ride_completed');

        $this->assertSame('Ride', $reference['type']);
        $this->assertSame($ride->pickup_address, $reference['origin']);
        $this->assertSame($ride->destination_address, $reference['destination']);
        $this->assertSame($ride->status, $reference['status']);
        $this->assertNotSame($reference['origin'], $reference['destination'], 'the route must not be one address twice');
    }

    #[Test]
    public function a_booking_reference_carries_the_booking_and_its_rides_route(): void
    {
        [$passenger, $ride, $booking] = $this->world();

        app(ScoreService::class)->recordPassengerNoShow($passenger, $booking, 'cash');

        $reference = $this->referenceFor($passenger, 'passenger_no_show');

        $this->assertSame('Booking', $reference['type']);
        $this->assertSame($booking->id, $reference['id']);
        $this->assertSame($booking->status, $reference['status']);

        $this->assertArrayHasKey('ride', $reference, 'a booking reference must carry its ride block');
        $this->assertSame($ride->id, $reference['ride']['id']);
        $this->assertSame($ride->pickup_address, $reference['ride']['origin']);
        $this->assertSame($ride->destination_address, $reference['ride']['destination']);
        $this->assertNotSame($reference['ride']['origin'], $reference['ride']['destination']);
    }

    /**
     * The reference block of the transaction whose action is $action, straight off the endpoint.
     *
     * @return array<string, mixed>
     */
    private function referenceFor(User $passenger, string $action): array
    {
        $response = $this->withToken($this->userToken($passenger))->getJson('/api/score/transactions');

        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('event.code', $action);

        $this->assertIsArray($row, "The endpoint did not answer a {$action} row for the passenger.");

        return $row['reference'];
    }

    /**
     * The smallest world the endpoint needs: a driver, a passenger, one ride with two DIFFERENT
     * addresses, and a COMPLETED booking on it.
     *
     * `completed` is not decoration: `ScoreService::recordRideCompleted()` awards the ride to a
     * passenger only through a booking whose status is `completed`, so a confirmed booking would
     * leave the passenger with no ride-referencing row at all.
     *
     * @return array{0: User, 1: Ride, 2: Booking}
     */
    private function world(): array
    {
        $passenger = User::factory()->create(['status' => 1]);
        $driver = User::factory()->create(['status' => 1, 'is_verified_driver' => true]);

        // RideBuilder writes a raw geometry row; `withAttributes` is where the addresses come from.
        $ride = RideBuilder::for($driver)->withAttributes([
            'pickup_address' => 'Damascus',
            'destination_address' => 'Aleppo',
        ])->create();

        $booking = Booking::create([
            'user_id' => $passenger->id,
            'ride_id' => $ride->id,
            'seats' => 1,
            'status' => Booking::COMPLETED,
            'communication_number' => '0911000001',
        ]);

        return [$passenger, $ride, $booking];
    }
}
