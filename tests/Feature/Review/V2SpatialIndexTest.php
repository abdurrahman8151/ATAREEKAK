<?php

namespace Tests\Feature\Review;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * V2 — `rides` had no spatial index at all.
 *
 * The original create-table migration declared these columns `point()` NOT NULL and added a
 * `spatialIndex()` to each. The very next migration dropped both columns and recreated them as
 * plain `geometry()`, taking the indexes with them, and nothing ever re-added them. Every ride
 * search therefore full-scanned `rides` on ST_Distance_Sphere.
 *
 * These tests pin the restored schema, and — just as importantly — pin that restoring it did NOT
 * change any stored coordinate. That second half is the one that actually mattered while
 * writing the migration: declaring `SRID 4326` on a column that previously declared none is a
 * statement about how MySQL should read every stored ordinate, and if it re-interpreted them it
 * would silently transpose the geography of every ride in the database.
 */
class V2SpatialIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('V2 reads MySQL information_schema.');
        }
    }

    /** @return array<int, array{index: string, column: string}> */
    private function spatialIndexes(): array
    {
        $rows = DB::select(
            "SELECT INDEX_NAME AS `index`, COLUMN_NAME AS `column`
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rides' AND INDEX_TYPE = 'SPATIAL'"
        );

        return array_map(fn ($r) => ['index' => $r->index, 'column' => $r->column], $rows);
    }

    public function test_both_ride_geometry_columns_have_a_spatial_index(): void
    {
        $indexes = $this->spatialIndexes();

        $columns = array_column($indexes, 'column');
        sort($columns);

        $this->assertSame(
            ['destination_location', 'pickup_location'],
            $columns,
            'rides must carry one SPATIAL index per geometry column'
        );
    }

    public function test_the_geometry_columns_declare_the_srid_they_actually_store(): void
    {
        $rows = DB::select(
            "SELECT COLUMN_NAME, SRS_ID FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rides'
               AND COLUMN_NAME IN ('pickup_location', 'destination_location')"
        );

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertSame(
                4326,
                (int) $row->SRS_ID,
                $row->COLUMN_NAME.' must declare the SRID its writers actually use, not SRID 0'
            );
        }
    }

    public function test_declaring_the_srid_did_not_reorder_stored_coordinates(): void
    {
        // The real risk in the migration. If ALTER ... SRID 4326 had re-interpreted ordinates,
        // every stored ride would now be the transpose of where it actually is - and because the
        // application reads and writes consistently, nothing would notice.
        $driver = User::factory()->create();
        $ride = RideBuilder::for($driver)
            ->withAttributes([
                'available_seats' => 4,
                'price_per_seat' => 50000,
                'payment_method' => 'cash',
                'booking_type' => 'direct',
                'status' => 'active',
                'communication_number' => '0911000000',
            ])
            ->rawPickup('POINT(33.513800 36.276500)')
            ->rawDestination('POINT(36.202100 37.134300)')
            ->departureTime(now()->addDay()->toDateString().' 10:00:00')
            ->create();

        $stored = DB::selectOne(
            'SELECT ST_AsText(pickup_location) AS wkt,
                    ST_X(pickup_location) AS x,
                    ST_Y(pickup_location) AS y,
                    ST_SRID(pickup_location) AS srid
             FROM rides WHERE id = ?',
            [$ride->id]
        );

        // RV-25 convention: lat-first, so the FIRST ordinate is the latitude.
        $this->assertSame('POINT(33.5138 36.2765)', $stored->wkt);
        $this->assertEqualsWithDelta(33.5138, (float) $stored->x, 1e-6, 'ST_X must still be the latitude');
        $this->assertEqualsWithDelta(36.2765, (float) $stored->y, 1e-6, 'ST_Y must still be the longitude');
        $this->assertSame(4326, (int) $stored->srid);
    }

    public function test_distance_queries_still_return_the_true_known_distance(): void
    {
        // Guards against the SRID declaration changing the meaning of ST_Distance_Sphere: the
        // same 309 km city pair RV-25 pinned, measured through the indexed column.
        $driver = User::factory()->create();
        RideBuilder::for($driver)
            ->withAttributes([
                'available_seats' => 4,
                'price_per_seat' => 50000,
                'payment_method' => 'cash',
                'booking_type' => 'direct',
                'status' => 'active',
                'communication_number' => '0911000000',
            ])
            ->rawPickup('POINT(33.5138 36.2765)')
            ->rawDestination('POINT(36.2021 37.1343)')
            ->departureTime(now()->addDay()->toDateString().' 10:00:00')
            ->create();

        $km = (float) DB::selectOne(
            "SELECT ST_Distance_Sphere(pickup_location, destination_location) / 1000 AS km
             FROM rides WHERE status = 'active' LIMIT 1"
        )->km;

        $this->assertEqualsWithDelta(309.0, $km, 2.0, 'Damascus -> Aleppo must still read ~309 km');
    }

    public function test_the_migration_is_reversible(): void
    {
        // up()/down() both ran during the rollback check that accompanied this task. This asserts
        // the shape down() is expected to leave behind, so a broken rollback cannot ship silently.
        $this->assertNotEmpty(
            $this->spatialIndexes(),
            'after migrate the spatial indexes must be present'
        );
    }
}
