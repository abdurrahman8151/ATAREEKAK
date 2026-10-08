<?php

namespace Tests\Feature\Review;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The replica `read.port` was an ARRAY (`'port' => [ ... ]`), which Laravel rejects at runtime.
 *
 * This is a latent bug found while closing RV-01: running `kyc:migrate-disk --dry-run` was the
 * first thing in this application that issued a SELECT before any write, and it died with
 * "Array to string conversion ... No connection could be made because the target machine actively
 * refused it".
 *
 * WHY THE ENTIRE SUITE MISSED IT. `RefreshDatabase` runs migrations before each test, which sets
 * Laravel's `recordsModified` flag, and `'sticky' => true` then routes every subsequent query to
 * the WRITE PDO. The `read` connection config is merged only for read queries, so it is never
 * exercised: 900+ tests pass while taking the write path every time. In production the first
 * query on a fresh connection is very often a read - looking up the user to log them in - so the
 * bug was live on every read-before-write path.
 *
 * The shape assertions below are the durable part. The `DB::purge()` round-trip is the behavioural
 * proof, and it works precisely because purging resets the sticky flag.
 */
class DBReplicaPortShapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_port_is_a_scalar_not_an_array(): void
    {
        $port = config('database.connections.mysql.read.port');

        $this->assertIsNotArray(
            $port,
            'read.port must be a scalar. Laravel merges the whole `read` array over the base config, '
            .'so an array here REPLACES the port and MySqlConnector interpolates it into the DSN'
        );
        $this->assertNotEmpty($port);
    }

    public function test_read_host_may_stay_an_array_because_laravel_supports_a_replica_list(): void
    {
        // The asymmetry is deliberate and worth pinning: `host` IS allowed to be an array,
        // `port` is not. Anyone "tidying" both to scalars would break replica selection.
        $this->assertIsArray(config('database.connections.mysql.read.host'));
        $this->assertIsArray(config('database.connections.mysql.write.host'));
    }

    public function test_the_merged_read_config_really_does_override_the_base_port(): void
    {
        // Reproduces ConnectionFactory::mergeReadWriteConfig() so the failure mode is documented
        // rather than inferred: array_merge($config, $read) is what turned the array into the live
        // port for every read query.
        $base = ['port' => config('database.connections.mysql.port')];
        $read = ['port' => config('database.connections.mysql.read.port')];
        $merged = array_merge($base, $read);

        $this->assertIsNotArray($merged['port']);
        $this->assertEquals($base['port'], $merged['port']);
    }

    /**
     * The behavioural proof. Purging drops the pooled connection AND resets the sticky
     * "recordsModified" flag, so the next query is forced down the READ path - the exact path the
     * array broke.
     */
    public function test_a_read_works_on_a_freshly_purged_connection(): void
    {
        DB::purge('mysql');

        $this->assertIsInt(
            DB::table('migrations')->count(),
            'a SELECT must work as the FIRST statement on a fresh connection'
        );
    }

    public function test_read_then_write_then_read_all_work(): void
    {
        // The full sequence production actually runs: read, write, read again - each after a purge,
        // so none of them can ride on the sticky write PDO that RefreshDatabase leaves behind.
        // `migrations` is used because it is the one table whose columns are known to every
        // environment; a hand-written INSERT into `users` proved to depend on this schema's
        // columns and is not what is being tested here anyway.
        DB::purge('mysql');
        $this->assertIsInt(DB::table('migrations')->count());

        DB::table('migrations')->where('migration', 'like', 'phpunit-read-write-probe%')->delete();

        DB::purge('mysql');
        $this->assertSame(
            0,
            DB::table('migrations')->where('migration', 'like', 'phpunit-read-write-probe%')->count(),
            'a write through the query builder must still succeed'
        );

        DB::purge('mysql');
        $this->assertSame(
            0,
            DB::table('migrations')->where('migration', 'like', 'phpunit-read-write-probe%')->count(),
            'a read after a write and another purge must still work'
        );
    }
}
