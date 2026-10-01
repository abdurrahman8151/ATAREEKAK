<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RV-24 — backfill the scalar pickup_lat/pickup_lng/destination_lat/destination_lng columns
 * from the stored geometry, so reading a ride's coordinates costs an attribute read instead of
 * a `SELECT ST_AsText(...) WHERE id = ?` per access (an N+1 across any list of rides).
 *
 * These columns already existed in the schema but were never populated: RideRepository
 * converted incoming coords to a geometry expression and then `unset()` the scalar keys
 * before insert, and the model's mutator wrote only the geometry column.
 *
 * Coordinate order is NOT decided here — it is inherited from the data. The stored geometry is
 * `POINT(lng lat)` (the mutator writes `POINT(%F %F)` with lng first, and the accessor parses
 * `sscanf('POINT(%f %f)', $lng, $lat)`), and MySQL's ST_X is the first ordinate / ST_Y the
 * second. So `lng = ST_X(...)`, `lat = ST_Y(...)` reproduces EXACTLY what the existing
 * accessors already return for the same row — verified against the same interpretation. If a
 * given row's stored point is transposed, this backfill copies that transposition faithfully
 * rather than silently "correcting" it; deciding whether production rows are transposed is
 * RV-25 and is explicitly NOT this migration's job.
 *
 * Idempotent: only touches rows whose scalars are still NULL. MySQL-guarded (ST_X/ST_Y are
 * MySQL); on any other driver this is a no-op and the accessor simply keeps using its
 * geometry-query fallback, which is always correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Populate from geometry. ST_Y = lat (second ordinate), ST_X = lng (first ordinate),
        // matching the accessor's sscanf('POINT(%f %f)', $lng, $lat) parse exactly.
        DB::statement(
            'UPDATE rides SET
                pickup_lat = ST_Y(pickup_location),
                pickup_lng = ST_X(pickup_location)
             WHERE pickup_lat IS NULL AND pickup_lng IS NULL
               AND pickup_location IS NOT NULL'
        );

        DB::statement(
            'UPDATE rides SET
                destination_lat = ST_Y(destination_location),
                destination_lng = ST_X(destination_location)
             WHERE destination_lat IS NULL AND destination_lng IS NULL
               AND destination_location IS NOT NULL'
        );
    }

    public function down(): void
    {
        // No-op: the backfill is derived data. Reverting the model change alone restores the
        // previous (slower) read path without needing to discard the columns, and dropping
        // populated columns would throw away real coordinate data.
    }
};
