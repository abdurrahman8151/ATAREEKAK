<?php

namespace Tests\Feature\Review;

use App\Models\User;
use App\Modules\Ride\Models\Booking;
use App\Modules\Ride\Models\Ride;
use App\Modules\Ride\Models\ScoreTransaction;
use App\Modules\Ride\Services\ScoreService;
use App\Modules\Ride\Support\StoredClassNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Concerns\ActsAsStaff;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * Stage 4 B1 / ADR 0010 - the stored class name is PINNED (owner decision D1 = PIN-OLD, 2026-11-20).
 *
 * `score_transactions.reference_type` is the only column in the database that holds a PHP class name. Stage 2
 * moved the two classes it names, so rows written after the move held the new namespace while everything older
 * held the two pre-move names. The owner chose to stop the drift: NEW rows write the
 * pre-Stage-2 value again, and READERS resolve both generations through one map
 * (`App\Modules\Ride\Support\StoredClassNames`).
 *
 * This file pins all three halves of that decision: the map, the WRITE, and the READ of BOTH generations - the
 * third is the one a snapshot cannot prove, because the aliaser rewrites every scalar inside a reference block.
 */
final class StoredClassNamesTest extends TestCase
{
    use ActsAsStaff;
    use RefreshDatabase;

    #[Test]
    public function the_map_is_the_only_place_that_knows_the_stored_names(): void
    {
        $legacyRide = StoredClassNames::storedFor(Ride::class);
        $legacyBooking = StoredClassNames::storedFor(Booking::class);

        // the WRITE side: the pinned, pre-Stage-2 value
        $this->assertSame($legacyRide, StoredClassNames::storedFor(Ride::class));
        $this->assertSame($legacyBooking, StoredClassNames::storedFor(Booking::class));

        // the READ side: both generations resolve to the SAME current class
        $this->assertSame(Ride::class, StoredClassNames::classFor($legacyRide));
        $this->assertSame(Ride::class, StoredClassNames::classFor(Ride::class));
        $this->assertSame(Booking::class, StoredClassNames::classFor($legacyBooking));
        $this->assertSame(Booking::class, StoredClassNames::classFor(Booking::class));

        // the API's short name is the same for both, which is what the corpus pins
        $this->assertSame('Ride', StoredClassNames::basenameFor($legacyRide));
        $this->assertSame('Ride', StoredClassNames::basenameFor(Ride::class));

        // an unknown value is left exactly as it is (no guessing, and no silent rewriting)
        $this->assertSame('not-a-class-name', StoredClassNames::storedFor('not-a-class-name'));
        $this->assertNull(StoredClassNames::classFor('not-a-class-name'));
        $this->assertNull(StoredClassNames::storedFor(null));
    }

    #[Test]
    public function a_new_ride_reference_row_writes_the_pre_stage_2_value(): void
    {
        [$passenger, $ride] = $this->world(completed: true);

        app(ScoreService::class)->recordRideCompleted($ride);

        $this->assertSame(
            StoredClassNames::storedFor(Ride::class),
            ScoreTransaction::where('user_id', $passenger->id)->where('action', 'ride_completed')->value('reference_type'),
            'a new row must keep writing the value production has held since before the move'
        );
    }

    #[Test]
    public function a_new_booking_reference_row_writes_the_pre_stage_2_value(): void
    {
        [$passenger, $ride, $booking] = $this->world();

        app(ScoreService::class)->recordPassengerNoShow($passenger, $booking, 'cash');

        $this->assertSame(
            StoredClassNames::storedFor(Booking::class),
            ScoreTransaction::where('user_id', $passenger->id)->where('action', 'passenger_no_show')->value('reference_type')
        );
    }

    #[Test]
    public function a_row_with_the_old_value_and_one_with_the_new_value_both_read(): void
    {
        [$passenger, $ride] = $this->world(completed: true);

        // One row exactly as production has held it for years, and one exactly as Stage 2 wrote it.
        foreach ([StoredClassNames::storedFor(Ride::class), Ride::class] as $stored) {
            ScoreTransaction::create([
                'user_id' => $passenger->id,
                'action' => 'ride_completed',
                'points' => 1,
                'previous_score' => 100,
                'new_score' => 101,
                'reference_type' => $stored,
                'reference_id' => $ride->id,
                'reason' => 'pinned-value characterization',
            ]);
        }

        $response = $this->withToken($this->userToken($passenger))->getJson('/api/score/transactions');
        $response->assertOk();

        $rideRows = collect($response->json('data'))
            ->filter(fn ($row) => ($row['reference']['type'] ?? null) === 'Ride')
            ->values();

        $this->assertCount(2, $rideRows, 'BOTH stored generations must read back as a Ride reference');

        foreach ($rideRows as $row) {
            $this->assertSame('Ride', $row['reference']['type']);
            $this->assertSame($ride->id, $row['reference']['id']);
            // The block is HYDRATED, not just labelled: this is the part the aliaser cannot pin.
            $this->assertSame($ride->pickup_address, $row['reference']['origin']);
            $this->assertSame($ride->destination_address, $row['reference']['destination']);
        }
    }

    /**
     * The smallest world the endpoint needs; `$completed` mirrors the neighbouring values test's reason (a
     * ride-completion award only reaches a passenger through a COMPLETED booking).
     *
     * @return array{0: User, 1: Ride, 2: Booking}
     */
    private function world(bool $completed = false): array
    {
        $passenger = User::factory()->create(['status' => 1]);
        $driver = User::factory()->create(['status' => 1, 'is_verified_driver' => true]);

        $ride = RideBuilder::for($driver)->withAttributes([
            'pickup_address' => 'Damascus',
            'destination_address' => 'Aleppo',
        ])->create();

        $booking = Booking::create([
            'user_id' => $passenger->id,
            'ride_id' => $ride->id,
            'seats' => 1,
            'status' => $completed ? Booking::COMPLETED : Booking::CONFIRMED,
            'communication_number' => '0911000001',
        ]);

        return [$passenger, $ride, $booking];
    }
}
