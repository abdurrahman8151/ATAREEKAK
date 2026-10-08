<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V2 — restore the SPATIAL indexes on `rides` and declare the SRID the data already carries.
 *
 * Recorded since R1 sec 3 / sec 9. The original create-table migration
 * (`2025_05_19_135630_create_rides_table`) declared these as `point()` NOT NULL and added a
 * `spatialIndex()` on each. The very next migration,
 * `2025_05_20_143208_fix_ride_spatial_columns`, DROPPED both columns and recreated them as plain
 * `geometry()` — which silently took the two spatial indexes with them. Nothing ever re-added
 * them, so every ride search has full-scanned `rides` on ST_Distance_Sphere ever since. The
 * audit's "2 spatial indexes" was the pre-drop state and has been stale ever since.
 *
 * Declaring SRID 4326 is not cosmetic: the column is declared with NO SRID (i.e. SRID 0) while
 * every writer uses ST_GeomFromText(..., 4326). MySQL accepts that mismatch and stores the value
 * as 4326 anyway, so the schema has been describing the data incorrectly for its whole life.
 * Aligning the declaration makes the axis-order contract (RV-25) explicit at the schema level
 * rather than an unwritten convention.
 *
 * SAFETY — verified on the scratch server before this file was written, not assumed:
 *   1. An SRID-0 column accepts an SRID-4326 value and preserves it as 4326.
 *   2. `ALTER ... MODIFY ... SRID 4326` does NOT re-interpret stored ordinates. On a throwaway
 *      table, POINT(33.5138 36.2765) read back byte-identical before and after the ALTER. This was
 *      the specific risk, because a column that silently swapped X/Y on every row would corrupt
 *      real geography.
 *   3. ST_X / ST_Y semantics are unchanged by the declaration: for the lat-first geometry RV-25
 *      writes, ST_X = 33.5138 (lat) and ST_Y = 36.2765 (lng), before and after.
 *   4. Both columns are already NOT NULL, which SPATIAL indexes require, and `SPATIAL KEY` is
 *      accepted on both the current column and an SRID-4326 one.
 *
 * The column type stays `geometry` rather than narrowing to `point`: narrowing would be a second,
 * unrelated change, and SPATIAL indexes work on any geometry column.
 *
 * Idempotent by inspection rather than by a guard — a fresh `migrate` replays the create-table
 * and the drop/recreate pair, so this runs against a schema with no spatial indexes either way.
 * `hasIndex()` makes the index creation safe to re-run, and the SRID change is a no-op when the
 * declared SRID already matches.
 *
 * MySQL-guarded; on any other driver it is a no-op, matching the other geo migrations.
 */
return new class extends Migration
{
    private const PICKUP_INDEX = 'rides_pickup_location_spatial';

    private const DESTINATION_INDEX = 'rides_destination_location_spatial';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Declare the SRID the stored geometry actually carries. See the safety notes above:
        // this does not reorder ordinates.
        DB::statement('ALTER TABLE rides MODIFY pickup_location GEOMETRY NOT NULL SRID 4326');
        DB::statement('ALTER TABLE rides MODIFY destination_location GEOMETRY NOT NULL SRID 4326');

        if (! $this->hasIndex(self::PICKUP_INDEX)) {
            DB::statement('ALTER TABLE rides ADD SPATIAL INDEX '.self::PICKUP_INDEX.' (pickup_location)');
        }

        if (! $this->hasIndex(self::DESTINATION_INDEX)) {
            DB::statement('ALTER TABLE rides ADD SPATIAL INDEX '.self::DESTINATION_INDEX.' (destination_location)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if ($this->hasIndex(self::PICKUP_INDEX)) {
            DB::statement('ALTER TABLE rides DROP INDEX '.self::PICKUP_INDEX);
        }

        if ($this->hasIndex(self::DESTINATION_INDEX)) {
            DB::statement('ALTER TABLE rides DROP INDEX '.self::DESTINATION_INDEX);
        }

        DB::statement('ALTER TABLE rides MODIFY pickup_location GEOMETRY NOT NULL');
        DB::statement('ALTER TABLE rides MODIFY destination_location GEOMETRY NOT NULL');
    }

    private function hasIndex(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 AS present FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'rides\' AND INDEX_NAME = ?
             LIMIT 1',
            [$name]
        ) !== null;
    }
};
