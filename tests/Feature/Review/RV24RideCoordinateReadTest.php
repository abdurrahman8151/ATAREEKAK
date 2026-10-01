<?php

namespace Tests\Feature\Review;

use App\Models\Ride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RideBuilder;
use Tests\TestCase as BaseTestCase;

/**
 * RV-24 ratchet — reading a ride's coordinates must not cost a per-access query once the
 * scalar columns exist, AND the fast path must return EXACTLY what the legacy geometry-query
 * parse returned (this is a performance change, never a semantic one).
 *
 * Background: getPickupLocationAttribute()/getDestinationLocationAttribute() ran
 * `SELECT ST_AsText(col) … WHERE id = ?` on EVERY attribute access, so any list of rides paid
 * one extra query per ride (RideResource alone touches pickup_location and destination_location
 * three times per ride — the N+1). The scalar pickup_lat/pickup_lng/destination_lat/
 * destination_lng columns already existed in the schema but were never written (RideRepository
 * unset() them before insert; the mutator wrote only geometry), so reads were forced through
 * geometry.
 *
 * The invariant pinned here is the important one: the column fast path must return the SAME
 * value the old `sscanf('POINT(%f %f)', $lng, $lat)` geometry parse produced for the same
 * stored point, so enabling it cannot change any response payload. Coordinate ORDER is
 * inherited from the stored data (first ordinate = lng via ST_X, second = lat via ST_Y) — this
 * test does NOT decide whether any row is transposed; that is RV-25.
 *
 * Both paths are exercised via the project's own RideBuilder:
 *   - create()          → raw DB::table insert, geometry only, scalars NULL → the FALLBACK path
 *                         (also exactly how the seeders/factory/artisan flows write, which
 *                          bypass the mutator, and how legacy rows look before the backfill)
 *   - viaModelMutators()→ Eloquent model write path → mutator populates scalars → FAST path
 */
class RV24RideCoordinateReadTest extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * What the geometry fallback returns: parse the stored WKT.
     * RV-25: storage is now POINT(lat lng) (MySQL EPSG:4326 axis-order = lat first), so the
     * FIRST ordinate is latitude — the opposite of the pre-RV-25 lng-first convention this
     * test originally pinned.
     */
    private function legacyGeometryParse(int $id, string $column): ?array
    {
        $row = DB::selectOne("SELECT ST_AsText(`{$column}`) AS wkt FROM `rides` WHERE `id` = ?", [$id]);
        if (! $row || ! isset($row->wkt)) {
            return null;
        }
        // RV-25: POINT(lat lng) — first ordinate is lat.
        sscanf($row->wkt, 'POINT(%f %f)', $lat, $lng);

        return ['lat' => $lat, 'lng' => $lng];
    }

    /** @test */
    public function the_fast_path_and_the_geometry_fallback_return_identical_coordinates(): void
    {
        // A raw-insert ride: geometry present, scalars NULL → accessor uses the fallback.
        $raw = RideBuilder::for()->create();
        $this->assertNull($raw->getAttributes()['pickup_lat'] ?? null,
            'precondition: a raw RideBuilder insert has no scalar lat (it is the fallback case)');

        $viaFallback = Ride::find($raw->id)->pickup_location;
        $legacy = $this->legacyGeometryParse($raw->id, 'pickup_location');
        $this->assertSame($legacy, $viaFallback,
            'with no scalars, the accessor must equal the legacy geometry parse exactly');

        // Populate the scalars the way the mutator/backfill does, then reload: FAST path.
        // RV-25: storage is POINT(lat lng), so ST_X (first ordinate) is LATITUDE and ST_Y is
        // LONGITUDE — the scalars must be populated lat=ST_X, lng=ST_Y (the opposite of the
        // pre-RV-25 convention).
        DB::statement(
            'UPDATE rides SET pickup_lat = ST_X(pickup_location), pickup_lng = ST_Y(pickup_location) WHERE id = ?',
            [$raw->id]
        );
        $viaFastPath = Ride::find($raw->id)->pickup_location;

        // The whole point: fast path == fallback == legacy. Performance change, not semantic.
        $this->assertSame($viaFallback, $viaFastPath,
            'the scalar fast path must return exactly what the geometry fallback returned');
        $this->assertSame($legacy, $viaFastPath);
    }

    /** @test */
    public function the_model_mutator_path_populates_scalars_consistently_with_the_geometry(): void
    {
        // The application write path (createRide / createWithRoute assign pickup_location as
        // ['lat'=>..,'lng'=>..] and rely on the mutator). The scalars it writes must match
        // ST_X/ST_Y of the geometry it wrote alongside them.
        $ride = RideBuilder::for()->viaModelMutators(33.5138, 36.2765, 36.2021, 37.1343);

        $attrs = $ride->getAttributes();
        $this->assertNotNull($attrs['pickup_lat'] ?? null, 'mutator must populate pickup_lat');
        $this->assertNotNull($attrs['pickup_lng'] ?? null, 'mutator must populate pickup_lng');
        $this->assertNotNull($attrs['destination_lat'] ?? null, 'mutator must populate destination_lat');
        $this->assertNotNull($attrs['destination_lng'] ?? null, 'mutator must populate destination_lng');

        $row = DB::selectOne('SELECT ST_AsText(pickup_location) AS wkt FROM rides WHERE id = ?', [$ride->id]);
        // RV-25: POINT(lat lng) — first ordinate is latitude, second is longitude.
        sscanf($row->wkt, 'POINT(%f %f)', $lat, $lng);
        $this->assertEqualsWithDelta($lat, $ride->pickup_location['lat'], 0.00000001,
            'scalar lat must equal the FIRST ordinate of the stored geometry (RV-25 lat-first)');
        $this->assertEqualsWithDelta($lng, $ride->pickup_location['lng'], 0.00000001,
            'scalar lng must equal the SECOND ordinate of the stored geometry (RV-25 lat-first)');
    }

    /** @test */
    public function reading_populated_coordinates_issues_zero_queries(): void
    {
        // The N+1 itself: a model-written ride has scalars, so touching the accessors three
        // times each (mirroring RideResource) must fire no further query.
        $ride = RideBuilder::for()->viaModelMutators();
        $loaded = Ride::find($ride->id); // one query to load

        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        for ($i = 0; $i < 3; $i++) {
            $loaded->pickup_location['lat'];
            $loaded->destination_location['lat'];
        }

        $this->assertSame(0, $count,
            'reading coordinates from populated scalar columns must issue zero queries '
            .'(this is the RV-24 N+1 — got '.$count.' instead)');
    }

    /** @test */
    public function an_unsaved_ride_read_does_not_error(): void
    {
        // The accessor must never fatal on a brand-new (unsaved) model.
        $ride = new Ride;
        $result = $ride->pickup_location;

        $this->assertTrue(
            $result === null || (is_array($result) && isset($result['lat'], $result['lng'])),
            'an unsaved ride must return null or a lat/lng array, never an error'
        );
    }
}
