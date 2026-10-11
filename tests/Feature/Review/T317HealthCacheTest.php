<?php

namespace Tests\Feature\Review;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T3-17 (owner choice: keep SELECT 1 for the Aiven keep-alive, cache it for 60 seconds).
 *
 * /up must keep returning 200 when the database is healthy. A repeat probe inside the window must
 * not query the database again. A failing probe must still return 500 and must not be cached.
 */
class T317HealthCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('health.up.db_ping');
    }

    public function test_healthy_probe_returns_ok_and_repeat_probe_skips_the_database(): void
    {
        $this->get('/up')->assertStatus(200);

        // Count queries on the second probe: the cached ping must not run SELECT 1 again.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/up')->assertStatus(200);
        $ping = collect(DB::getQueryLog())->filter(fn ($q) => $q['query'] === 'SELECT 1')->count();
        DB::disableQueryLog();

        $this->assertSame(0, $ping, 'A repeat probe inside 60 seconds must be served from cache.');
    }

    public function test_failing_probe_returns_500_and_is_not_cached(): void
    {
        Cache::forget('health.up.db_ping');

        // Force the ping to fail by pointing the database connection at a dead handle.
        DB::shouldReceive('select')->with('SELECT 1')->andThrow(new \RuntimeException('db down'));

        $this->get('/up')->assertStatus(500)
            ->assertSee('Service unavailable', false);

        $this->assertFalse(Cache::has('health.up.db_ping'), 'A failed ping must not be cached.');
    }
}
