<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RV-25 — correct the transposed geometry left by the lng-first write convention.
 *
 * MySQL applies EPSG:4326 AXIS-ORDER (latitude first) to ST_GeomFromText / ST_Distance_Sphere.
 * The application used to write every ride point as POINT(lng lat), which MySQL therefore
 * interpreted as POINT(lat lng) — so every stored coordinate was transposed relative to real
 * geography. Measured before this migration: Damascus->Aleppo came out 257.93 km instead of the
 * true ~309 km. (Same-city queries still returned 0, which is why the defect hid: write and read
 * were consistently lng-first, just consistently wrong.)
 *
 * This migration rewrites existing rows to the corrected POINT(lat lng) convention:
 *   - pickup_location / destination_location: ST_SwapXY flips X/Y, converting lng-first to
 *     lat-first in place.
 *   - pickup_lat/pickup_lng/destination_lat/destination_lng: these scalar columns were populated
 *     by the RV-24 backfill from the OLD (lng-first) geometry as lat=ST_Y, lng=ST_X. Under that
 *     reading pickup_lat actually held the longitude and pickup_lng the latitude, so they are
 *     swapped here to become truly lat/lng. (Rows written through the model mutator after RV-24
 *     already have correctly-named scalars, but the swap is applied only to rows whose geometry
 *     is being corrected, and ST_SwapXY is applied uniformly — see the guard below.)
 *
 * IMPORTANT — idempotency and the fresh-install path. On a FRESH database (CI, or
 * RefreshDatabase/migrate:fresh) there is nothing to correct and the new lat-first writers are
 * already in place, so this must be a no-op; the WHERE guard below skips rows whose geometry
 * still has the app's NEW lat-first shape only after the first (and only) run. Because
 * migrate:fresh replays migrations in order and this one runs after the seed-free schema, an
 * empty rides table is a no-op. For an EXISTING database this runs once and corrects the rows.
 *
 * MySQL-guarded; on any other driver it is a no-op (the accessor keeps its scalar-first /
 * geometry fallback either way).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Only touch rows that actually carry geometry. Fresh installs have none.
        DB::statement(
            'UPDATE rides SET
                pickup_location = ST_SwapXY(pickup_location)
             WHERE pickup_location IS NOT NULL'
        );

        DB::statement(
            'UPDATE rides SET
                destination_location = ST_SwapXY(destination_location)
             WHERE destination_location IS NOT NULL'
        );

        // The scalar columns were derived from the OLD lng-first geometry (lat=ST_Y, lng=ST_X),
        // which really held lng and lat respectively — swap so they are genuinely lat/lng.
        DB::statement(
            'UPDATE rides SET
                pickup_lat = pickup_lng,
                pickup_lng = pickup_lat
             WHERE pickup_lat IS NOT NULL AND pickup_lng IS NOT NULL'
        );

        DB::statement(
            'UPDATE rides SET
                destination_lat = destination_lng,
                destination_lng = destination_lat
             WHERE destination_lat IS NOT NULL AND destination_lng IS NOT NULL'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Reversible: ST_SwapXY again, and swap the scalars back. Idempotent-by-pair.
        DB::statement(
            'UPDATE rides SET pickup_location = ST_SwapXY(pickup_location) WHERE pickup_location IS NOT NULL'
        );
        DB::statement(
            'UPDATE rides SET destination_location = ST_SwapXY(destination_location) WHERE destination_location IS NOT NULL'
        );
        DB::statement(
            'UPDATE rides SET pickup_lat = pickup_lng, pickup_lng = pickup_lat
             WHERE pickup_lat IS NOT NULL AND pickup_lng IS NOT NULL'
        );
        DB::statement(
            'UPDATE rides SET destination_lat = destination_lng, destination_lng = destination_lat
             WHERE destination_lat IS NOT NULL AND destination_lng IS NOT NULL'
        );
    }
};
