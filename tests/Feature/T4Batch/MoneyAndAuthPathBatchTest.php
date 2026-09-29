<?php

namespace Tests\Feature\T4Batch;

use App\Models\Employee;
use App\Models\Ride;
use App\Models\StaffRefreshToken;
use App\Models\User;
use App\Services\Staff\StaffJwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * T4 batch — money/security path correctness.
 *
 *   T4-2  Ride::scopeNearLocation() built `ST_GeomFromText('POINT(? ?)', 4326)`
 *         with the coordinates as bindings — the `?` sat INSIDE a quoted SQL
 *         literal, so nothing bound and the geometry argument was the literal
 *         text "POINT(? ?)". The scope also type-hinted the Query\Builder while
 *         Laravel hands local scopes an Eloquent\Builder, so it could never run.
 *         Both are fixed; this suite proves the scope actually returns the
 *         rides inside the radius and excludes those outside it.
 *
 *   T4-3  StaffJwtService::cleanupExpiredTokens() relied on operator
 *         precedence: where(expires<now)->orWhere(revoked)->delete() happened
 *         to mean "expired OR revoked" only because no other clause existed.
 *         Now parenthesised, so adding a constraint later cannot silently
 *         widen the DELETE across the whole table.
 *
 *   T4-4  StaffJwtService hardcoded ACCESS_TTL = 3600s and ignored config.
 *         Both token families now read config (jwt.ttl / jwt.staff_ttl); the
 *         staff default reproduces the old value exactly.
 *
 * Requires MySQL: the rides table uses SPATIAL indexes and the resolution
 * path calls ST_Distance_Sphere.
 */
class MoneyAndAuthPathBatchTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    private User $passenger;

    protected function setUp(): void
    {
        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('Requires MySQL: SPATIAL indexes + ST_Distance_Sphere.');
        }
        parent::setUp();

        $this->driver = User::factory()->create(['is_verified_driver' => true]);
        $this->passenger = User::factory()->create(['is_verified_passenger' => true]);
    }

    /** Damascus (36.2765, 33.5138) and Aleppo (37.1343, 36.2021) — ~150 km apart. */
    private function insertRideAt(float $lng, float $lat, int $driverId): Ride
    {
        // RV-34: shared builder. Source point stays parametric; the destination and
        // every other value are reproduced from the previous fixture.
        return RideBuilder::forUserId($driverId)
            ->withAttributes([
                'pickup_address' => 'A',
                'destination_address' => 'B',
                'available_seats' => 3,
                'price_per_seat' => 50000,
                'payment_method' => 'e-pay',
                'booking_type' => 'direct',
                'status' => 'active',
                'distance' => 320.5,
                'duration' => 240,
                'communication_number' => '0911000000',
            ])
            ->rawPickup(sprintf('POINT(%F %F)', $lng, $lat))
            ->rawDestination('POINT(36.2021 37.1343)')
            ->departureTime(now()->addHour())
            ->create();
    }

    // ── T4-2 ────────────────────────────────────────────────────────────────
    public function test_near_location_returns_rides_inside_the_radius(): void
    {
        $this->insertRideAt(36.2765, 33.5138, $this->driver->id); // Damascus

        $found = Ride::nearLocation(33.5138, 36.2765, 10)->pluck('id');

        $this->assertCount(1, $found, 'the ride at the query point must be inside a 10 km radius');
    }

    public function test_near_location_excludes_rides_outside_the_radius(): void
    {
        $this->insertRideAt(36.2765, 33.5138, $this->driver->id); // Damascus

        // Same ride, queried from Aleppo with a 10 km radius → nothing.
        $found = Ride::nearLocation(36.2021, 37.1343, 10)->pluck('id');

        $this->assertCount(0, $found, 'a ride ~150 km away must not match a 10 km radius');
    }

    public function test_near_location_widens_with_the_radius(): void
    {
        $this->insertRideAt(36.2765, 33.5138, $this->driver->id);

        $this->assertCount(0, Ride::nearLocation(36.2021, 37.1343, 10)->pluck('id'));
        $this->assertCount(1, Ride::nearLocation(36.2021, 37.1343, 300)->pluck('id'));
    }

    public function test_near_location_binds_its_parameters(): void
    {
        // The bug was a literal 'POINT(? ?)' inside a quoted string: the
        // coordinates were never bound. Assert the generated SQL has a real
        // placeholder and the coordinates are bound values, not SQL text.
        $sql = Ride::nearLocation(33.5138, 36.2765, 10)->toRawSql();

        $this->assertStringNotContainsString(
            'POINT(?',
            $sql,
            'the WKT literal must not contain an unbound placeholder'
        );
        $this->assertStringContainsString('ST_GeomFromText(', $sql);
        // WKT POINT() is X-then-Y, i.e. longitude first. The scope takes
        // (latitude, longitude) and must transpose it into the bound value.
        $this->assertMatchesRegularExpression(
            '/POINT\(36\.27\d* 33\.51\d*\)/',
            $sql,
            'coordinates must be bound into the WKT value, as longitude then latitude'
        );
    }

    // ── T4-3 ────────────────────────────────────────────────────────────────
    public function test_cleanup_deletes_expired_and_revoked_tokens_only(): void
    {
        $employee = Employee::create([
            'username' => 'cleanup_'.uniqid(), 'email' => 'c'.uniqid().'@t.com',
            'password' => 'Password123!', 'first_name' => 'C', 'last_name' => 'L',
            'role' => 'admin', 'is_active' => true, 'token_version' => 0,
        ]);

        $make = function (bool $expired, bool $revoked) use ($employee): StaffRefreshToken {
            return StaffRefreshToken::create([
                'employee_id' => $employee->id,
                'token' => hash('sha256', Str::random(40).uniqid()),
                'expires_at' => $expired ? now()->subDay() : now()->addDay(),
                'revoked' => $revoked,
            ]);
        };

        $stale = $make(true, false);   // expired  → must go
        $revoked = $make(false, true);   // revoked  → must go
        $live = $make(false, false);  // live     → must stay
        $expiredRev = $make(true, true);    // both     → must go

        $deleted = app(StaffJwtService::class)->cleanupExpiredTokens();

        $this->assertSame(3, $deleted, 'expired, revoked and both must be cleaned');
        $this->assertNull(StaffRefreshToken::find($stale->id));
        $this->assertNull(StaffRefreshToken::find($revoked->id));
        $this->assertNull(StaffRefreshToken::find($expiredRev->id));
        $this->assertNotNull(StaffRefreshToken::find($live->id), 'a live, unrevoked token must survive cleanup');
    }

    public function test_cleanup_groups_the_or_inside_one_condition(): void
    {
        // Guards the structural intent: the DELETE must carry a single
        // parenthesised (a OR b) group, so a future extra clause cannot
        // de-parenthesise into (a) OR (b AND c) — the T4-3 hazard.
        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            if (stripos($q->sql, 'delete from') !== false) {
                $queries[] = $q->sql;
            }
        });

        app(StaffJwtService::class)->cleanupExpiredTokens();

        $this->assertNotEmpty($queries, 'cleanup must issue a delete');
        $delete = end($queries);
        $this->assertMatchesRegularExpression(
            '/where\s+\(\s*`?expires_at`?\s*<\s*\?\s+or\s+`?revoked`?\s*=\s*\?\s*\)/is',
            $delete,
            'the OR must be wrapped in its own parenthesised group'
        );
    }

    // ── T4-4 ────────────────────────────────────────────────────────────────
    public function test_staff_access_token_ttl_comes_from_config(): void
    {
        $employee = Employee::create([
            'username' => 'ttl_'.uniqid(), 'email' => 't'.uniqid().'@t.com',
            'password' => 'Password123!', 'first_name' => 'T', 'last_name' => 'L',
            'role' => 'admin', 'is_active' => true, 'token_version' => 0,
        ]);

        config(['jwt.staff_ttl' => 90]);

        $pair = app(StaffJwtService::class)->generateTokenPair($employee);

        $this->assertSame(90 * 60, $pair['expires_in'], 'expires_in must follow jwt.staff_ttl (minutes → seconds)');

        $payload = app(StaffJwtService::class)->decodeToken($pair['access_token']);
        $this->assertSame(
            $payload['exp'] - $payload['iat'],
            90 * 60,
            'the exp claim must follow the same config value'
        );
    }

    public function test_staff_ttl_default_preserves_the_previous_one_hour(): void
    {
        // The hardcoded constant was 3600 seconds. The shipped default must
        // reproduce it exactly — this is a behaviour-preservation pin.
        $this->assertSame(
            3600,
            (int) config('jwt.staff_ttl') * 60,
            'default staff TTL must equal the old hardcoded 3600 s'
        );
    }

    public function test_user_ttl_default_is_unchanged(): void
    {
        $this->assertSame(600, (int) config('jwt.ttl'), 'user access-token default must not move (JWT_TTL unset in this env)');
    }
}
