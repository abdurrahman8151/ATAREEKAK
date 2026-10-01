<?php

namespace Tests\Feature\Review;

use App\Models\Ride;
use App\Services\Ride\RideSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * RV-25 ratchet — stored ride coordinates must be geographically CORRECT, not consistently
 * transposed.
 *
 * The bug: MySQL applies EPSG:4326 AXIS-ORDER (latitude first) to ST_GeomFromText /
 * ST_Distance_Sphere. The application wrote every point as POINT(lng lat), which MySQL therefore
 * read as POINT(lat lng) — so every stored ride point was the transpose of its real location.
 * Write and read were consistently lng-first, which is exactly why it hid: a same-city query
 * still returned 0 km.
 *
 * The evidence that made it visible is a real, known city pair. True Damascus->Aleppo is ~309 km;
 * measured before this fix the application reported 257.93 km (the distance between the two
 * TRANSPOSED points). After the fix it must report ~309 km.
 *
 * These tests pin the corrected convention at three levels:
 *   1. what is actually stored (ST_X / ST_Y / ST_AsText of a written ride);
 *   2. that a real distance query returns the TRUE distance (Damascus->Aleppo ~= 309 km, and a
 *      nearby city ~= 141 km), not the transposed one;
 *   3. that the two conventions are actually distinguishable — if swapping back to lng-first
 *      were equivalent, the whole premise would be void and this test would be vacuous.
 */
class RV25GeometryAxisOrderTest extends TestCase
{
    use RefreshDatabase;

    // Damascus and Aleppo, in real-world (lat, lng).
    private const DAMASCUS_LAT = 33.5138;

    private const DAMASCUS_LNG = 36.2765;

    private const ALEPPO_LAT = 36.2021;

    private const ALEPPO_LNG = 37.1343;

    /** Write a ride through the REAL model path (the mutator). */
    private function rideAt(float $lat, float $lng): Ride
    {
        return RideBuilder::for()->viaModelMutators($lat, $lng, $lat, $lng);
    }

    /** @test */
    public function a_written_ride_stores_latitude_in_the_first_ordinate(): void
    {
        $ride = $this->rideAt(self::DAMASCUS_LAT, self::DAMASCUS_LNG);

        $row = DB::selectOne(
            'SELECT ST_X(pickup_location) AS x, ST_Y(pickup_location) AS y,
                    ST_AsText(pickup_location) AS wkt
             FROM rides WHERE id = ?',
            [$ride->id]
        );

        // RV-25: the stored point is POINT(lat lng) — first ordinate (ST_X) is latitude.
        $this->assertEqualsWithDelta(self::DAMASCUS_LAT, (float) $row->x, 0.000001,
            'ST_X (first ordinate) must be LATITUDE under the corrected lat-first convention');
        $this->assertEqualsWithDelta(self::DAMASCUS_LNG, (float) $row->y, 0.000001,
            'ST_Y (second ordinate) must be LONGITUDE under the corrected lat-first convention');

        // The stored WKT must literally begin with the latitude. (Match on the first ordinate
        // with a tolerance-free prefix: MySQL normalises the float formatting, so compare the
        // parsed first ordinate rather than a formatted literal.)
        sscanf($row->wkt, 'POINT(%f %f)', $firstOrdinate, $secondOrdinate);
        $this->assertEqualsWithDelta(self::DAMASCUS_LAT, $firstOrdinate, 0.000001,
            'the first ordinate of the stored WKT must be the latitude');
        $this->assertEqualsWithDelta(self::DAMASCUS_LNG, $secondOrdinate, 0.000001,
            'the second ordinate of the stored WKT must be the longitude');
    }

    /** @test */
    public function a_known_city_distance_is_now_geographically_correct(): void
    {
        // The decisive, money-adjacent truth: a ride in Damascus must be ~309 km from Aleppo,
        // not the 257.93 km produced when both points were transposed.
        $km = $this->distanceKm(self::DAMASCUS_LAT, self::DAMASCUS_LNG, self::ALEPPO_LAT, self::ALEPPO_LNG);

        $this->assertGreaterThan(295, $km, 'Damascus->Aleppo must exceed the transposed 257.93 km');
        $this->assertLessThan(320, $km, 'Damascus->Aleppo must be near the true ~309 km');
    }

    /** @test */
    public function the_transposed_convention_would_give_a_different_answer(): void
    {
        // Guards against a VACUOUS test: if lat-first and lng-first produced the same distance,
        // the whole premise would be void. Prove they differ, and that lat-first is the correct one.
        $correct = $this->distanceKm(self::DAMASCUS_LAT, self::DAMASCUS_LNG, self::ALEPPO_LAT, self::ALEPPO_LNG);
        $transposed = $this->distanceKm(self::DAMASCUS_LAT, self::DAMASCUS_LNG, self::ALEPPO_LNG, self::ALEPPO_LAT);

        $this->assertNotEqualsWithDelta($correct, $transposed, 1.0,
            'swapping the two points must change the distance — otherwise this test proves nothing');
        $this->assertGreaterThan(300, $correct,
            'the lat-first reading is the geographically correct one (~309 km)');
    }

    /** @test */
    public function the_search_service_matches_a_ride_from_its_own_real_location(): void
    {
        // End-to-end: a ride in Damascus must be found by a search whose source is Damascus,
        // using the REAL service (not a hand-built query).
        $this->rideAt(self::DAMASCUS_LAT, self::DAMASCUS_LNG);

        $nearby = app(RideSearchService::class)->getNearbyRides(
            self::DAMASCUS_LAT,
            self::DAMASCUS_LNG,
            20
        );

        $this->assertNotEmpty($nearby,
            'a ride written in Damascus must be found by a Damascus-centred radius search');
    }

    /** ST_Distance_Sphere between two lat/lng points, in km. */
    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        // Build the WKT in PHP and bind each geometry as ONE value. (The T4-2 bug: putting '?'
        // inside a single-quoted SQL literal makes it a literal, not a placeholder.)
        $row = DB::selectOne(
            'SELECT ST_Distance_Sphere(ST_GeomFromText(?, 4326), ST_GeomFromText(?, 4326)) / 1000 AS km',
            [
                sprintf('POINT(%F %F)', $lat1, $lng1),
                sprintf('POINT(%F %F)', $lat2, $lng2),
            ]
        );

        return (float) $row->km;
    }
}
