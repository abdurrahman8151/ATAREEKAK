<?php

namespace Tests\Feature\Review;

use App\Models\Ride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AF-13: `Ride::factory()` was unusable.
 *
 * `RideFactory` wrote its geometry columns with `DB::raw("ST_GeomFromText('POINT(...)')")`, a
 * `Query\Expression`, while `Ride::setPickupLocationAttribute(array $coords)` type-hints `array`. The
 * result was a `TypeError` on every single `Ride::factory()->create()`.
 *
 * It stayed invisible because no test called the factory. `RV11RideCountTest:350` works around it in a
 * comment, using the shared `RideBuilder` instead. This test exists so the landmine is defused rather
 * than merely known about.
 *
 * The geometry itself is deliberately unchanged. `GeoPoint::wkt()` is `sprintf('POINT(%F %F)', $lat,
 * $lng)` - latitude first - and the old raw literals were already in that order, so the named-coordinate
 * arrays below produce the same point. That matters: the transposition bug RV-25 describes was caused by
 * hand-rolled lng-first writes, so an "obvious" cleanup here could have silently flipped an axis.
 */
class AF13RideFactoryGeometryTest extends TestCase
{
    use RefreshDatabase;

    /** The factory's pickup, as the raw literal always stated it. */
    private const PICKUP_LAT = 33.5138;

    private const PICKUP_LNG = 36.2765;

    private const DESTINATION_LAT = 36.2021;

    private const DESTINATION_LNG = 37.1343;

    public function test_ride_factory_creates_a_ride(): void
    {
        $ride = Ride::factory()->create();

        $this->assertNotNull($ride->id);
        $this->assertDatabaseHas('rides', ['id' => $ride->id]);
    }

    public function test_ride_factory_can_be_overridden(): void
    {
        $ride = Ride::factory()->create(['available_seats' => 7, 'price_per_seat' => 1234]);

        $this->assertSame(7, $ride->available_seats);
        $this->assertSame(1234, $ride->price_per_seat);
    }

    public function test_the_factory_writes_geometry_in_latitude_first_order(): void
    {
        $ride = Ride::factory()->create();

        $point = DB::selectOne('SELECT ST_AsText(pickup_location) AS wkt FROM rides WHERE id = ?',
            [$ride->id]);

        // POINT(33.513800 36.276500): latitude first, exactly as the old raw literal stated it.
        $this->assertStringContainsString('33.5138', $point->wkt);
        $this->assertStringContainsString('36.2765', $point->wkt);
        $this->assertLessThan(
            strpos($point->wkt, '36.2765'),
            strpos($point->wkt, '33.5138'),
            'latitude must come first in the stored POINT - a transposed axis would reverse these offsets'
        );
    }

    public function test_the_accessor_round_trips_the_factory_coordinates(): void
    {
        $ride = Ride::factory()->create();

        // The accessor parses with sscanf('POINT(%f %f)', $lng, $lat), so a round-trip through the
        // model proves both the write order and the read order still agree.
        $this->assertEqualsWithDelta(self::PICKUP_LAT, $ride->pickup_location['lat'], 0.0001);
        $this->assertEqualsWithDelta(self::PICKUP_LNG, $ride->pickup_location['lng'], 0.0001);
    }

    /**
     * A `DB::raw` write bypasses the mutator, so pickup_lat / pickup_lng were left NULL and had to be
     * backfilled by migration (see the RV-24 comment on the mutator). Going through the mutator fills
     * them, which is what makes the read-fallback unnecessary for factory-built rides.
     */
    public function test_the_factory_populates_the_scalar_lat_lng_columns(): void
    {
        $ride = Ride::factory()->create();

        $row = DB::selectOne(
            'SELECT pickup_lat, pickup_lng, destination_lat, destination_lng FROM rides WHERE id = ?',
            [$ride->id]
        );

        $this->assertNotNull($row->pickup_lat, 'pickup_lat must be materialised by the mutator');
        $this->assertEqualsWithDelta(self::PICKUP_LAT, (float) $row->pickup_lat, 0.0001);
        $this->assertEqualsWithDelta(self::PICKUP_LNG, (float) $row->pickup_lng, 0.0001);
        $this->assertEqualsWithDelta(self::DESTINATION_LAT, (float) $row->destination_lat, 0.0001);
        $this->assertEqualsWithDelta(self::DESTINATION_LNG, (float) $row->destination_lng, 0.0001);
    }

    /**
     * Destination is asserted separately because a transposed write would still produce a valid POINT;
     * only the coordinate VALUES distinguish the two orientations.
     */
    public function test_destination_coordinates_are_not_transposed(): void
    {
        $ride = Ride::factory()->create();

        $this->assertEqualsWithDelta(self::DESTINATION_LAT, $ride->destination_location['lat'], 0.0001);
        $this->assertEqualsWithDelta(self::DESTINATION_LNG, $ride->destination_location['lng'], 0.0001);
        $this->assertNotEquals(
            round(self::DESTINATION_LNG, 4),
            round($ride->destination_location['lat'], 4),
            'a transposed destination would store the longitude as the latitude'
        );
    }
}
