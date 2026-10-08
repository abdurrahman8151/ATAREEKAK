<?php

namespace Tests\Feature\Review;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 — proving the SRID declaration is safe for rows that ALREADY EXIST.
 *
 * This is a separate class, and it deliberately does NOT use RefreshDatabase.
 *
 * Why it cannot live with the rest of the V2 tests: RefreshDatabase migrates an EMPTY schema, so
 * a migration that silently transposed every pre-existing ride would pass every test in
 * V2SpatialIndexTest — those tests write their own row *after* the migration has already run, so
 * the corruption never touches anything they look at. That was found the honest way: injecting
 * `UPDATE rides SET pickup_location = ST_SwapXY(pickup_location)` into the migration left all
 * five V2 tests green.
 *
 * Production is exactly the case those tests miss. This one creates a clone of `rides`, populates
 * it, runs the real ALTER against populated data, and compares the ordinates before and after.
 * It needs no RefreshDatabase because it only ever creates and drops its own scratch table — and
 * it must not use one, because DDL in MySQL commits implicitly and would break the surrounding
 * test's transaction.
 */
class V2SridAlterSafetyTest extends TestCase
{
    private const PROBE_TABLE = 'zz_v2_srid_probe';

    protected function setUp(): void
    {
        parent::setUp();

        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('V2 needs MySQL spatial semantics.');
        }
    }

    protected function tearDown(): void
    {
        DB::statement('DROP TABLE IF EXISTS '.self::PROBE_TABLE);
        parent::tearDown();
    }

    /**
     * The pre-migration column definition, verbatim: GEOMETRY NOT NULL with NO declared SRID.
     *
     * `CREATE TABLE ... LIKE rides` was tried first and is wrong here — it also copies every other
     * NOT NULL column (driver_id, status, ...), which an insert would then have to supply. The
     * point of this table is to hold geometry and nothing else.
     */
    private function createProbeTable(): void
    {
        DB::statement('DROP TABLE IF EXISTS '.self::PROBE_TABLE);
        DB::statement(
            'CREATE TABLE '.self::PROBE_TABLE.' (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pickup_location GEOMETRY NOT NULL,
                destination_location GEOMETRY NOT NULL
            ) ENGINE=InnoDB'
        );
    }

    public function test_declaring_the_srid_preserves_the_ordinates_of_rows_that_already_exist(): void
    {
        $this->createProbeTable();

        // Three real coordinate pairs, including a transposed-looking one, so a swap is visible.
        $pairs = [
            ['POINT(33.5138 36.2765)', 'POINT(36.2021 37.1343)'],   // Damascus -> Aleppo
            ['POINT(35.0000 35.0000)', 'POINT(35.1000 35.1000)'],
            ['POINT(-33.8688 151.2093)', 'POINT(-37.8136 144.9631)'], // southern + eastern hemisphere
        ];

        foreach ($pairs as [$from, $to]) {
            // Written with SRID 4326, exactly as the application's writers do.
            DB::statement(
                'INSERT INTO '.self::PROBE_TABLE.' (pickup_location, destination_location) VALUES (ST_GeomFromText(?, 4326), ST_GeomFromText(?, 4326))',
                [$from, $to]
            );
        }

        $before = DB::select('SELECT id, ST_AsText(pickup_location) AS p, ST_AsText(destination_location) AS d FROM '.self::PROBE_TABLE.' ORDER BY id');
        $this->assertCount(3, $before);

        // THE ALTER under test - byte-identical to the one the migration runs.
        DB::statement('ALTER TABLE '.self::PROBE_TABLE.' MODIFY pickup_location GEOMETRY NOT NULL SRID 4326');
        DB::statement('ALTER TABLE '.self::PROBE_TABLE.' MODIFY destination_location GEOMETRY NOT NULL SRID 4326');

        $after = DB::select('SELECT id, ST_AsText(pickup_location) AS p, ST_AsText(destination_location) AS d FROM '.self::PROBE_TABLE.' ORDER BY id');

        $this->assertSame(
            array_map(fn ($r) => [$r->id, $r->p, $r->d], $before),
            array_map(fn ($r) => [$r->id, $r->p, $r->d], $after),
            'ALTER ... SRID 4326 must not re-interpret stored ordinates: every stored point would '
            .'otherwise be silently transposed, and because the app reads and writes consistently, '
            .'nothing downstream would notice'
        );

        // And the distance across a known city pair must survive it too.
        $km = (float) DB::selectOne(
            'SELECT ST_Distance_Sphere(pickup_location, destination_location) / 1000 AS km
             FROM '.self::PROBE_TABLE.' ORDER BY id LIMIT 1'
        )->km;

        $this->assertEqualsWithDelta(309.0, $km, 2.0, 'Damascus -> Aleppo must still read ~309 km after the ALTER');
    }

    public function test_the_alter_is_also_safe_when_repeated(): void
    {
        // A deploy that re-runs migrate, or a retry after a partial failure, must be a no-op
        // rather than an error or a second transformation.
        DB::statement('DROP TABLE IF EXISTS '.self::PROBE_TABLE);
        $this->createProbeTable();
        DB::statement('INSERT INTO '.self::PROBE_TABLE." (pickup_location, destination_location) VALUES (ST_GeomFromText('POINT(33.5138 36.2765)', 4326), ST_GeomFromText('POINT(36.2021 37.1343)', 4326))");

        for ($i = 0; $i < 2; $i++) {
            DB::statement('ALTER TABLE '.self::PROBE_TABLE.' MODIFY pickup_location GEOMETRY NOT NULL SRID 4326');
            DB::statement('ALTER TABLE '.self::PROBE_TABLE.' MODIFY destination_location GEOMETRY NOT NULL SRID 4326');
        }

        $wkt = DB::selectOne('SELECT ST_AsText(pickup_location) AS p FROM '.self::PROBE_TABLE)->p;
        $this->assertSame('POINT(33.5138 36.2765)', $wkt, 're-running the ALTER must not move the point');
    }
}
