<?php

namespace Tests\Unit\Services\Admin;

use App\Models\Booking;
use App\Models\Photo;
use App\Models\Ride;
use App\Models\User;
use App\Models\UserRating;
use App\Services\Admin\AdminDriverService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AdminDriverServiceTest
 *
 * ── Two app-side details this file works around (not bugs I'm fixing here) ──
 *
 * 1. UserObserver::created() fires on every User::factory()->create():
 *      - auto-creates a Profile with profile_photo already pointed at the
 *        default placeholder image (so "no photo" scenarios must explicitly
 *        null it out — a fresh user is never truly photo-less).
 *      - if a user's email matches config('admin.system_admin.email'), it
 *        auto-seeds a 3.0 UserRating from that admin onto every subsequently
 *        created user. setUp() points that config at a non-existent email so
 *        this never fires and rating/avg_rating assertions stay exact.
 *
 * 2. getDriverProfile()'s stats.total_rides is derived from $driver->rides
 *    ->count() on a relation eager-loaded with ->limit(5) — so it's really
 *    "count of the 5 most recent rides", not the true lifetime count, once a
 *    driver has more than 5. getDriverDashboard() does NOT share this bug
 *    (it re-queries Ride::count() independently). Both behaviors are tested
 *    as-is below.
 *
 * Time is frozen to a fixed Wednesday via Carbon::setTestNow() so that
 * getVerificationEfficiency()'s day/week/month boundary arithmetic is fully
 * deterministic regardless of when the suite actually runs.
 */
class AdminDriverServiceTest extends TestCase
{
    use RefreshDatabase;

    private AdminDriverService $service;
    private Carbon $frozenNow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AdminDriverService::class);

        Config::set('admin.system_admin.email', 'no-admin-configured@test.invalid');

        // 2026-01-14 12:00:00 is a Wednesday: comfortably mid-week and
        // mid-month, so startOfWeek/endOfWeek/startOfMonth/etc. all land
        // where the tests below expect them to.
        $this->frozenNow = Carbon::create(2026, 1, 14, 12, 0, 0);
        Carbon::setTestNow($this->frozenNow);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // getDashboardData
    // ═══════════════════════════════════════════════════════════════════════

    public function test_get_dashboard_data_returns_all_expected_top_level_keys(): void
    {
        $data = $this->service->getDashboardData();

        $this->assertArrayHasKey('admin_photo', $data);
        $this->assertArrayHasKey('stats', $data);
        $this->assertArrayHasKey('recent_activity', $data);
        $this->assertArrayHasKey('verification_efficiency', $data);
    }

    public function test_get_dashboard_data_works_with_null_admin_id(): void
    {
        $data = $this->service->getDashboardData(null);

        $this->assertNull($data['admin_photo']);
    }

    public function test_get_dashboard_data_includes_stats_shape(): void
    {
        $data = $this->service->getDashboardData();

        foreach (['total_drivers', 'active_drivers', 'pending_verifications', 'suspended_drivers', 'average_rating'] as $key) {
            $this->assertArrayHasKey($key, $data['stats']);
        }
    }

    public function test_get_dashboard_data_verification_efficiency_defaults_to_week(): void
    {
        $data = $this->service->getDashboardData();

        $this->assertEquals('week', $data['verification_efficiency']['period']);
    }

    public function test_get_dashboard_data_admin_photo_uses_given_admin_id(): void
    {
        $admin = $this->makeUser();
        $admin->profile->update(['profile_photo' => 'profiles/profile_photo/admin.jpg']);

        $data = $this->service->getDashboardData($admin->id);

        $this->assertStringContainsString('admin.jpg', $data['admin_photo']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // getStats
    // ═══════════════════════════════════════════════════════════════════════

    public function test_get_stats_returns_zeroes_when_no_drivers_exist(): void
    {
        $stats = $this->service->getStats();

        $this->assertEquals(0, $stats['total_drivers']);
        $this->assertEquals(0, $stats['active_drivers']);
        $this->assertEquals(0, $stats['pending_verifications']);
        $this->assertEquals(0, $stats['suspended_drivers']);
        $this->assertEquals(0.0, $stats['average_rating']);
    }

    public function test_get_stats_total_drivers_counts_verified_drivers(): void
    {
        $this->makeUser(['is_verified_driver' => true]);

        $this->assertEquals(1, $this->service->getStats()['total_drivers']);
    }

    public function test_get_stats_total_drivers_counts_pending_applicants_with_documents(): void
    {
        $user = $this->makeUser(['verification_status' => 'pending']);
        $this->attachDriverDocs($user);

        $this->assertEquals(1, $this->service->getStats()['total_drivers']);
    }

    public function test_get_stats_total_drivers_counts_rejected_applicants_with_documents(): void
    {
        $user = $this->makeUser(['verification_status' => 'rejected']);
        $this->attachDriverDocs($user);

        $this->assertEquals(1, $this->service->getStats()['total_drivers']);
    }

    public function test_get_stats_total_drivers_excludes_pending_applicants_without_documents(): void
    {
        $this->makeUser(['verification_status' => 'pending']);

        $this->assertEquals(0, $this->service->getStats()['total_drivers']);
    }

    public function test_get_stats_total_drivers_excludes_documents_of_the_wrong_type(): void
    {
        $user = $this->makeUser(['verification_status' => 'pending']);
        $this->attachDriverDocs($user, ['face_id', 'back_id']);

        $this->assertEquals(0, $this->service->getStats()['total_drivers']);
    }

    public function test_get_stats_total_drivers_excludes_plain_users(): void
    {
        $this->makeUser(['verification_status' => 'none']);

        $this->assertEquals(0, $this->service->getStats()['total_drivers']);
    }

    public function test_get_stats_active_drivers_only_counts_is_verified_driver_flag(): void
    {
        $this->makeUser(['is_verified_driver' => true]);
        $pending = $this->makeUser(['verification_status' => 'pending']);
        $this->attachDriverDocs($pending);

        $this->assertEquals(1, $this->service->getStats()['active_drivers']);
    }

    public function test_get_stats_pending_verifications_requires_documents(): void
    {
        $this->makeUser(['verification_status' => 'pending']);

        $this->assertEquals(0, $this->service->getStats()['pending_verifications']);
    }

    public function test_get_stats_pending_verifications_counts_users_with_documents(): void
    {
        $user = $this->makeUser(['verification_status' => 'pending']);
        $this->attachDriverDocs($user);

        $this->assertEquals(1, $this->service->getStats()['pending_verifications']);
    }

    public function test_get_stats_pending_verifications_excludes_rejected(): void
    {
        $user = $this->makeUser(['verification_status' => 'rejected']);
        $this->attachDriverDocs($user);

        $stats = $this->service->getStats();

        $this->assertEquals(0, $stats['pending_verifications']);
        $this->assertEquals(1, $stats['total_drivers']); // rejected still counts as "total"
    }

    public function test_get_stats_suspended_drivers_is_always_zero(): void
    {
        $this->makeUser(['status' => 0, 'is_verified_driver' => true]);

        $this->assertEquals(0, $this->service->getStats()['suspended_drivers']);
    }

    public function test_get_stats_average_rating_only_includes_verified_drivers(): void
    {
        $verified   = $this->makeUser(['is_verified_driver' => true]);
        $unverified = $this->makeUser(['is_verified_driver' => false]);
        $rater      = $this->makeUser();

        $this->rate($rater, $verified, 5.0);
        $this->rate($rater, $unverified, 1.0);

        $this->assertEquals(5.0, $this->service->getStats()['average_rating']);
    }

    public function test_get_stats_average_rating_averages_multiple_ratings(): void
    {
        $driver = $this->makeUser(['is_verified_driver' => true]);
        $this->rate($this->makeUser(), $driver, 4.0);
        $this->rate($this->makeUser(), $driver, 5.0);

        $this->assertEquals(4.5, $this->service->getStats()['average_rating']);
    }

    public function test_get_stats_average_rating_rounds_to_two_decimals(): void
    {
        $driver = $this->makeUser(['is_verified_driver' => true]);
        $this->rate($this->makeUser(), $driver, 5.0);
        $this->rate($this->makeUser(), $driver, 4.0);
        $this->rate($this->makeUser(), $driver, 4.0);
        // 13 / 3 = 4.3333... -> 4.33

        $this->assertEquals(4.33, $this->service->getStats()['average_rating']);
    }

    public function test_get_stats_average_rating_is_zero_when_no_ratings_exist(): void
    {
        $this->makeUser(['is_verified_driver' => true]);

        $this->assertEquals(0.0, $this->service->getStats()['average_rating']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // getDrivers
    // ═══════════════════════════════════════════════════════════════════════

    public function test_get_drivers_returns_a_paginator(): void
    {
        $this->assertInstanceOf(LengthAwarePaginator::class, $this->service->getDrivers());
    }

    public function test_get_drivers_default_filter_includes_verified_drivers(): void
    {
        $driver = $this->makeUser(['is_verified_driver' => true]);

        $result = $this->service->getDrivers();

        $this->assertCount(1, $result->items());
        $this->assertEquals($driver->id, $result->items()[0]->id);
    }

    public function test_get_drivers_default_filter_includes_pending_applicants_with_documents(): void
    {
        $applicant = $this->makeUser(['verification_status' => 'pending']);
        $this->attachDriverDocs($applicant);

        $this->assertCount(1, $this->service->getDrivers()->items());
    }

    public function test_get_drivers_default_filter_excludes_plain_users(): void
    {
        $this->makeUser();

        $this->assertCount(0, $this->service->getDrivers()->items());
    }

    public function test_get_drivers_verified_filter_excludes_pending_applicants(): void
    {
        $verified = $this->makeUser(['is_verified_driver' => true]);
        $pending  = $this->makeUser(['verification_status' => 'pending']);
        $this->attachDriverDocs($pending);

        $result = $this->service->getDrivers('verified');

        $this->assertCount(1, $result->items());
        $this->assertEquals($verified->id, $result->items()[0]->id);
    }

    public function test_get_drivers_pending_filter_returns_only_pending_with_documents(): void
    {
        $pending = $this->makeUser(['verification_status' => 'pending']);
        $this->attachDriverDocs($pending);
        $this->makeUser(['is_verified_driver' => true]);

        $result = $this->service->getDrivers('pending');

        $this->assertCount(1, $result->items());
        $this->assertEquals($pending->id, $result->items()[0]->id);
    }

    public function test_get_drivers_pending_filter_excludes_pending_without_documents(): void
    {
        $this->makeUser(['verification_status' => 'pending']);

        $this->assertCount(0, $this->service->getDrivers('pending')->items());
    }

    public function test_get_drivers_suspended_filter_returns_status_zero_drivers(): void
    {
        $suspended = $this->makeUser(['is_verified_driver' => true, 'status' => 0]);
        $this->makeUser(['is_verified_driver' => true, 'status' => 1]);

        $result = $this->service->getDrivers('suspended');

        $this->assertCount(1, $result->items());
        $this->assertEquals($suspended->id, $result->items()[0]->id);
    }

    public function test_get_drivers_search_matches_first_name(): void
    {
        $this->makeUser(['is_verified_driver' => true, 'first_name' => 'Zaid']);
        $this->makeUser(['is_verified_driver' => true, 'first_name' => 'Omar']);

        $this->assertCount(1, $this->service->getDrivers('all', 10, 1, 'Zaid')->items());
    }

    public function test_get_drivers_search_matches_last_name(): void
    {
        $this->makeUser(['is_verified_driver' => true, 'last_name' => 'Alzoubi']);
        $this->makeUser(['is_verified_driver' => true, 'last_name' => 'Hassan']);

        $this->assertCount(1, $this->service->getDrivers('all', 10, 1, 'Alzoubi')->items());
    }

    public function test_get_drivers_search_matches_email(): void
    {
        $this->makeUser(['is_verified_driver' => true, 'email' => 'findme@example.com']);
        $this->makeUser(['is_verified_driver' => true, 'email' => 'other@example.com']);

        $this->assertCount(1, $this->service->getDrivers('all', 10, 1, 'findme')->items());
    }

    public function test_get_drivers_search_excludes_non_drivers_even_if_name_matches(): void
    {
        $this->makeUser(['first_name' => 'Zaid']); // not verified, no docs

        $this->assertCount(0, $this->service->getDrivers('all', 10, 1, 'Zaid')->items());
    }

    public function test_get_drivers_search_returns_empty_when_nothing_matches(): void
    {
        $this->makeUser(['is_verified_driver' => true, 'first_name' => 'Zaid']);

        $this->assertCount(0, $this->service->getDrivers('all', 10, 1, 'NoSuchName')->items());
    }

    public function test_get_drivers_orders_newest_first(): void
    {
        $older = $this->makeUser(['is_verified_driver' => true]);
        $this->forceTimestamp('users', $older->id, 'created_at', now()->subDays(2));

        $newer = $this->makeUser(['is_verified_driver' => true]);
        $this->forceTimestamp('users', $newer->id, 'created_at', now());

        $items = $this->service->getDrivers()->items();

        $this->assertEquals($newer->id, $items[0]->id);
        $this->assertEquals($older->id, $items[1]->id);
    }

    public function test_get_drivers_respects_per_page(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->makeUser(['is_verified_driver' => true]);
        }

        $result = $this->service->getDrivers('all', 2, 1);

        $this->assertCount(2, $result->items());
        $this->assertEquals(5, $result->total());
        $this->assertEquals(3, $result->lastPage());
    }

    public function test_get_drivers_respects_page_number(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->makeUser(['is_verified_driver' => true]);
        }

        $result = $this->service->getDrivers('all', 1, 2);

        $this->assertEquals(2, $result->currentPage());
        $this->assertCount(1, $result->items());
    }

    public function test_get_drivers_attaches_avg_rating_when_ratings_exist(): void
    {
        $driver = $this->makeUser(['is_verified_driver' => true]);
        $this->rate($this->makeUser(), $driver, 4.0);

        $this->assertEquals(4.0, $this->service->getDrivers()->items()[0]->avg_rating);
    }

    public function test_get_drivers_avg_rating_is_null_when_no_ratings(): void
    {
        $this->makeUser(['is_verified_driver' => true]);

        $this->assertNull($this->service->getDrivers()->items()[0]->avg_rating);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // formatDriver
    // ═══════════════════════════════════════════════════════════════════════

    public function test_format_driver_includes_id_and_driver_ref(): void
    {
        $driver = $this->makeUser();

        $formatted = $this->service->formatDriver($this->reloadDriver($driver->id));

        $this->assertEquals($driver->id, $formatted['id']);
        $this->assertEquals('#DR-' . $driver->id, $formatted['driver_ref']);
    }

    public function test_format_driver_trims_full_name(): void
    {
        $driver = $this->makeUser(['first_name' => 'Zaid', 'last_name' => 'Alzoubi']);

        $formatted = $this->service->formatDriver($this->reloadDriver($driver->id));

        $this->assertEquals('Zaid Alzoubi', $formatted['full_name']);
    }

    public function test_format_driver_profile_photo_is_null_when_explicitly_removed(): void
    {
        $driver = $this->makeUser();
        $driver->profile->update(['profile_photo' => null]);

        $formatted = $this->service->formatDriver($this->reloadDriver($driver->id));

        $this->assertNull($formatted['profile_photo']);
    }

    public function test_format_driver_profile_photo_returns_asset_url(): void
    {
        $driver = $this->makeUser();
        $driver->profile->update(['profile_photo' => 'profiles/profile_photo/custom.jpg']);

        $formatted = $this->service->formatDriver($this->reloadDriver($driver->id));

        $this->assertStringContainsString('custom.jpg', $formatted['profile_photo']);
    }

    public function test_format_driver_vehicle_is_null_without_type_of_car(): void
    {
        $driver = $this->makeUser();

        $formatted = $this->service->formatDriver($this->reloadDriver($driver->id));

        $this->assertNull($formatted['vehicle']);
    }

    public function test_format_driver_vehicle_shows_type_only_when_no_color(): void
    {
        $driver = $this->makeUser();
        $driver->profile->update(['type_of_car' => 'Toyota Camry']);

        $formatted = $this->service->formatDriver($this->reloadDriver($driver->id));

        $this->assertEquals('Toyota Camry', $formatted['vehicle']);
    }

    public function test_format_driver_vehicle_combines_type_and_color(): void
    {
        $driver = $this->makeUser();
        $driver->profile->update(['type_of_car' => 'Toyota Camry', 'color_of_car' => 'White']);

        $formatted = $this->service->formatDriver($this->reloadDriver($driver->id));

        $this->assertEquals('Toyota Camry | White', $formatted['vehicle']);
    }

    public function test_format_driver_phone_is_null_without_rides(): void
    {
        $driver = $this->makeUser();

        $formatted = $this->service->formatDriver($this->reloadDriver($driver->id));

        $this->assertNull($formatted['phone']);
    }

    public function test_format_driver_phone_uses_most_recently_created_ride(): void
    {
        $driver = $this->makeUser();
        $this->insertRide($driver->id, ['communication_number' => '0911111111', 'created_at' => now()->subDay()]);
        $this->insertRide($driver->id, ['communication_number' => '0922222222', 'created_at' => now()]);

        $formatted = $this->service->formatDriver($this->reloadDriver($driver->id));

        $this->assertEquals('0922222222', $formatted['phone']);
    }

    public function test_format_driver_phone_skips_rides_with_null_number(): void
    {
        $driver = $this->makeUser();
        $this->insertRide($driver->id, ['communication_number' => '0911111111', 'created_at' => now()->subDay()]);
        $this->insertRide($driver->id, ['communication_number' => null, 'created_at' => now()]);

        $formatted = $this->service->formatDriver($this->reloadDriver($driver->id));

        $this->assertEquals('0911111111', $formatted['phone']);
    }

    /**
     * @dataProvider driverStatusProvider
     */
    public function test_format_driver_status_resolution(array $attributes, string $expectedStatus): void
    {
        $driver = $this->makeUser($attributes);

        $formatted = $this->service->formatDriver($this->reloadDriver($driver->id));

        $this->assertEquals($expectedStatus, $formatted['status']);
    }

    public static function driverStatusProvider(): array
    {
        return [
            'verified'                       => [['is_verified_driver' => true, 'status' => 1], 'verified'],
            'pending'                        => [['verification_status' => 'pending', 'status' => 1], 'pending'],
            'rejected'                       => [['verification_status' => 'rejected', 'status' => 1], 'rejected'],
            'unverified (status none)'      => [['verification_status' => 'none', 'status' => 1], 'unverified'],
            'suspended overrides verified'  => [['is_verified_driver' => true, 'status' => 0], 'suspended'],
        ];
    }

    public function test_format_driver_avg_rating_is_null_when_not_attached(): void
    {
        $driver = $this->makeUser();

        $formatted = $this->service->formatDriver($this->reloadDriver($driver->id));

        $this->assertNull($formatted['avg_rating']);
    }

    public function test_format_driver_avg_rating_rounds_to_one_decimal(): void
    {
        $driver = $this->reloadDriver($this->makeUser()->id);
        $driver->avg_rating = 4.567;

        $formatted = $this->service->formatDriver($driver);

        $this->assertEquals(4.6, $formatted['avg_rating']);
    }

    public function test_format_driver_is_verified_driver_cast_to_bool(): void
    {
        $driver = $this->makeUser(['is_verified_driver' => true]);

        $formatted = $this->service->formatDriver($this->reloadDriver($driver->id));

        $this->assertIsBool($formatted['is_verified_driver']);
        $this->assertTrue($formatted['is_verified_driver']);
    }

    public function test_format_driver_verification_status_is_passed_through(): void
    {
        $driver = $this->makeUser(['verification_status' => 'rejected']);

        $formatted = $this->service->formatDriver($this->reloadDriver($driver->id));

        $this->assertEquals('rejected', $formatted['verification_status']);
    }

    public function test_format_driver_joined_at_is_iso8601(): void
    {
        $driver = $this->makeUser();

        $formatted = $this->service->formatDriver($this->reloadDriver($driver->id));

        $this->assertEquals($driver->created_at->toIso8601String(), $formatted['joined_at']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // getDriverProfile
    // ═══════════════════════════════════════════════════════════════════════

    public function test_get_driver_profile_throws_when_driver_does_not_exist(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->getDriverProfile(999999);
    }

    public function test_get_driver_profile_top_level_shape(): void
    {
        $driver = $this->makeUser();

        $profile = $this->service->getDriverProfile($driver->id);

        foreach ([
                     'id', 'driver_ref', 'full_name', 'email', 'profile_photo', 'phone', 'address', 'gender',
                     'verification_status', 'is_verified_driver', 'status', 'joined_at', 'vehicle',
                     'documents', 'rating', 'stats', 'recent_rides',
                 ] as $key) {
            $this->assertArrayHasKey($key, $profile, "Missing key: {$key}");
        }
    }

    public function test_get_driver_profile_returns_basic_identity_fields(): void
    {
        $driver = $this->makeUser([
            'first_name' => 'Zaid',
            'last_name'  => 'Alzoubi',
            'email'      => 'zaid@example.com',
            'gender'     => 'M',
            'address'    => 'دمشق',
        ]);

        $profile = $this->service->getDriverProfile($driver->id);

        $this->assertEquals('#DR-' . $driver->id, $profile['driver_ref']);
        $this->assertEquals('Zaid Alzoubi', $profile['full_name']);
        $this->assertEquals('zaid@example.com', $profile['email']);
        $this->assertEquals('M', $profile['gender']);
        $this->assertEquals('دمشق', $profile['address']);
    }

    public function test_get_driver_profile_status_reflects_suspended(): void
    {
        $driver = $this->makeUser(['is_verified_driver' => true, 'status' => 0]);

        $this->assertEquals('suspended', $this->service->getDriverProfile($driver->id)['status']);
    }

    public function test_get_driver_profile_phone_resolves_from_latest_ride(): void
    {
        $driver = $this->makeUser();
        $this->insertRide($driver->id, ['communication_number' => '0933333333']);

        $this->assertEquals('0933333333', $this->service->getDriverProfile($driver->id)['phone']);
    }

    public function test_get_driver_profile_documents_are_keyed_by_type(): void
    {
        $driver = $this->makeUser();
        Photo::create(['user_id' => $driver->id, 'type' => 'license', 'path' => 'verifications/license/a.jpg']);
        Photo::create(['user_id' => $driver->id, 'type' => 'face_id', 'path' => 'verifications/face_id/b.jpg']);

        $documents = $this->service->getDriverProfile($driver->id)['documents'];

        $this->assertTrue($documents->has('license'));
        $this->assertTrue($documents->has('face_id'));
        $this->assertStringContainsString('a.jpg', $documents->get('license'));
    }

    public function test_get_driver_profile_vehicle_fields_are_null_without_data(): void
    {
        $driver = $this->makeUser();

        $vehicle = $this->service->getDriverProfile($driver->id)['vehicle'];

        $this->assertNull($vehicle['type']);
        $this->assertNull($vehicle['color']);
        $this->assertNull($vehicle['seats']);
        $this->assertNull($vehicle['photo']);
    }

    public function test_get_driver_profile_vehicle_fields_reflect_profile_data(): void
    {
        $driver = $this->makeUser();
        $driver->profile->update([
            'type_of_car'     => 'Kia Sportage',
            'color_of_car'    => 'Black',
            'number_of_seats' => 4,
            'car_pic'         => 'cars/kia.jpg',
        ]);

        $vehicle = $this->service->getDriverProfile($driver->id)['vehicle'];

        $this->assertEquals('Kia Sportage', $vehicle['type']);
        $this->assertEquals('Black', $vehicle['color']);
        $this->assertEquals(4, $vehicle['seats']);
        $this->assertStringContainsString('kia.jpg', $vehicle['photo']);
    }

    public function test_get_driver_profile_rating_average_is_null_without_ratings(): void
    {
        $driver = $this->makeUser();

        $rating = $this->service->getDriverProfile($driver->id)['rating'];

        $this->assertNull($rating['average']);
        $this->assertEquals(0, $rating['total_ratings']);
    }

    public function test_get_driver_profile_rating_average_rounds_to_two_decimals(): void
    {
        $driver = $this->makeUser();
        $this->rate($this->makeUser(), $driver, 5.0);
        $this->rate($this->makeUser(), $driver, 4.0);
        $this->rate($this->makeUser(), $driver, 4.0);

        $rating = $this->service->getDriverProfile($driver->id)['rating'];

        $this->assertEquals(4.33, $rating['average']);
        $this->assertEquals(3, $rating['total_ratings']);
    }

    public function test_get_driver_profile_completed_rides_counts_finished_status_only(): void
    {
        $driver = $this->makeUser();
        $this->insertRide($driver->id, ['status' => 'finished']);
        $this->insertRide($driver->id, ['status' => 'finished']);
        $this->insertRide($driver->id, ['status' => 'cancelled']);

        $this->assertEquals(2, $this->service->getDriverProfile($driver->id)['stats']['completed_rides']);
    }

    public function test_get_driver_profile_total_rides_matches_actual_count_when_under_the_cap(): void
    {
        $driver = $this->makeUser();
        $this->insertRide($driver->id);
        $this->insertRide($driver->id);

        $this->assertEquals(2, $this->service->getDriverProfile($driver->id)['stats']['total_rides']);
    }

    public function test_get_driver_profile_total_rides_is_capped_by_the_five_ride_eager_load_limit(): void
    {
        // Documented behavior, not fixed here: see class docblock.
        $driver = $this->makeUser();
        for ($i = 0; $i < 7; $i++) {
            $this->insertRide($driver->id, ['created_at' => now()->subMinutes($i)]);
        }

        $this->assertEquals(5, $this->service->getDriverProfile($driver->id)['stats']['total_rides']);
    }

    public function test_get_driver_profile_recent_rides_limited_to_five(): void
    {
        $driver = $this->makeUser();
        for ($i = 0; $i < 7; $i++) {
            $this->insertRide($driver->id, ['created_at' => now()->subMinutes($i)]);
        }

        $this->assertCount(5, $this->service->getDriverProfile($driver->id)['recent_rides']);
    }

    public function test_get_driver_profile_recent_rides_ordered_newest_first(): void
    {
        $driver = $this->makeUser();
        $older  = $this->insertRide($driver->id, ['created_at' => now()->subDays(2)]);
        $newer  = $this->insertRide($driver->id, ['created_at' => now()]);

        $recentRides = $this->service->getDriverProfile($driver->id)['recent_rides'];

        $this->assertEquals($newer->id, $recentRides[0]['id']);
        $this->assertEquals($older->id, $recentRides[1]['id']);
    }

    public function test_get_driver_profile_recent_rides_shape(): void
    {
        $driver = $this->makeUser();
        $this->insertRide($driver->id, [
            'pickup_address'      => 'Damascus',
            'destination_address' => 'Aleppo',
            'status'              => 'active',
        ]);

        $ride = $this->service->getDriverProfile($driver->id)['recent_rides'][0];

        $this->assertEquals('Damascus', $ride['pickup_address']);
        $this->assertEquals('Aleppo', $ride['destination_address']);
        $this->assertEquals('active', $ride['status']);
        $this->assertArrayHasKey('departure_time', $ride);
        $this->assertArrayHasKey('bookings_count', $ride);
    }

    public function test_get_driver_profile_recent_rides_include_booking_counts(): void
    {
        $driver = $this->makeUser();
        $ride   = $this->insertRide($driver->id);
        $this->makeBooking($ride, $this->makeUser(), 1, 'confirmed');
        $this->makeBooking($ride, $this->makeUser(), 1, 'confirmed');

        $this->assertEquals(2, $this->service->getDriverProfile($driver->id)['recent_rides'][0]['bookings_count']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // getDriverDashboard
    // ═══════════════════════════════════════════════════════════════════════

    public function test_get_driver_dashboard_throws_when_driver_does_not_exist(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->getDriverDashboard(999999);
    }

    public function test_get_driver_dashboard_top_level_shape(): void
    {
        $driver = $this->makeUser();

        $dashboard = $this->service->getDriverDashboard($driver->id);

        foreach ([
                     'id', 'driver_ref', 'full_name', 'email', 'phone', 'gender', 'address', 'joined_at',
                     'status', 'is_verified', 'verification_status', 'profile_photo', 'rating', 'stats',
                     'vehicle', 'documents', 'recent_rides', 'favorite_destination',
                 ] as $key) {
            $this->assertArrayHasKey($key, $dashboard, "Missing key: {$key}");
        }
    }

    public function test_get_driver_dashboard_uses_is_verified_key_not_is_verified_driver(): void
    {
        // Unlike getDriverProfile()/formatDriver(), this method's key is
        // literally "is_verified", not "is_verified_driver".
        $driver = $this->makeUser(['is_verified_driver' => true]);

        $dashboard = $this->service->getDriverDashboard($driver->id);

        $this->assertArrayHasKey('is_verified', $dashboard);
        $this->assertArrayNotHasKey('is_verified_driver', $dashboard);
        $this->assertTrue($dashboard['is_verified']);
    }

    public function test_get_driver_dashboard_returns_basic_identity_fields(): void
    {
        $driver = $this->makeUser(['first_name' => 'Zaid', 'last_name' => 'Alzoubi', 'email' => 'zaid@example.com']);

        $dashboard = $this->service->getDriverDashboard($driver->id);

        $this->assertEquals('#DR-' . $driver->id, $dashboard['driver_ref']);
        $this->assertEquals('Zaid Alzoubi', $dashboard['full_name']);
        $this->assertEquals('zaid@example.com', $dashboard['email']);
    }

    public function test_get_driver_dashboard_status_reflects_suspended(): void
    {
        $driver = $this->makeUser(['is_verified_driver' => true, 'status' => 0]);

        $this->assertEquals('suspended', $this->service->getDriverDashboard($driver->id)['status']);
    }

    public function test_get_driver_dashboard_phone_resolves_from_latest_ride(): void
    {
        $driver = $this->makeUser();
        $this->insertRide($driver->id, ['communication_number' => '0944444444']);

        $this->assertEquals('0944444444', $this->service->getDriverDashboard($driver->id)['phone']);
    }

    public function test_get_driver_dashboard_profile_photo_null_when_absent(): void
    {
        $driver = $this->makeUser();
        $driver->profile->update(['profile_photo' => null]);

        $this->assertNull($this->service->getDriverDashboard($driver->id)['profile_photo']);
    }

    public function test_get_driver_dashboard_profile_photo_returns_url_when_present(): void
    {
        $driver = $this->makeUser();
        $driver->profile->update(['profile_photo' => 'profiles/profile_photo/driver.jpg']);

        $this->assertStringContainsString('driver.jpg', $this->service->getDriverDashboard($driver->id)['profile_photo']);
    }

    public function test_get_driver_dashboard_stats_counts_are_not_capped_like_get_driver_profile(): void
    {
        $driver = $this->makeUser();
        for ($i = 0; $i < 12; $i++) {
            $this->insertRide($driver->id, ['status' => 'finished']);
        }

        $stats = $this->service->getDriverDashboard($driver->id)['stats'];

        $this->assertEquals(12, $stats['total_rides']);
        $this->assertEquals(12, $stats['completed_rides']);
    }

    public function test_get_driver_dashboard_stats_completed_and_cancelled_counts(): void
    {
        $driver = $this->makeUser();
        $this->insertRide($driver->id, ['status' => 'finished']);
        $this->insertRide($driver->id, ['status' => 'finished']);
        $this->insertRide($driver->id, ['status' => 'cancelled']);
        $this->insertRide($driver->id, ['status' => 'active']);

        $stats = $this->service->getDriverDashboard($driver->id)['stats'];

        $this->assertEquals(4, $stats['total_rides']);
        $this->assertEquals(2, $stats['completed_rides']);
        $this->assertEquals(1, $stats['cancelled_rides']);
    }

    public function test_get_driver_dashboard_cancel_rate_is_zero_without_rides(): void
    {
        $driver = $this->makeUser();

        $this->assertEquals(0.0, $this->service->getDriverDashboard($driver->id)['stats']['cancel_rate']);
    }

    public function test_get_driver_dashboard_cancel_rate_calculation(): void
    {
        $driver = $this->makeUser();
        $this->insertRide($driver->id, ['status' => 'finished']);
        $this->insertRide($driver->id, ['status' => 'finished']);
        $this->insertRide($driver->id, ['status' => 'cancelled']);
        $this->insertRide($driver->id, ['status' => 'finished']);
        $this->insertRide($driver->id, ['status' => 'finished']);

        // 1 cancelled / 5 total = 20%
        $this->assertEquals(20.0, $this->service->getDriverDashboard($driver->id)['stats']['cancel_rate']);
    }

    public function test_get_driver_dashboard_cancel_rate_rounds_to_one_decimal(): void
    {
        $driver = $this->makeUser();
        // 3 cancelled / 7 total = 42.857...% -> 42.9%
        $this->insertRide($driver->id, ['status' => 'cancelled']);
        $this->insertRide($driver->id, ['status' => 'cancelled']);
        $this->insertRide($driver->id, ['status' => 'cancelled']);
        $this->insertRide($driver->id, ['status' => 'finished']);
        $this->insertRide($driver->id, ['status' => 'finished']);
        $this->insertRide($driver->id, ['status' => 'finished']);
        $this->insertRide($driver->id, ['status' => 'finished']);

        $this->assertEquals(42.9, $this->service->getDriverDashboard($driver->id)['stats']['cancel_rate']);
    }

    public function test_get_driver_dashboard_earnings_is_zero_without_completed_bookings(): void
    {
        $driver = $this->makeUser();
        $ride   = $this->insertRide($driver->id, ['price_per_seat' => 50000]);
        $this->makeBooking($ride, $this->makeUser(), 2, 'confirmed'); // not completed

        $this->assertEquals(0.0, $this->service->getDriverDashboard($driver->id)['stats']['total_earnings']);
    }

    public function test_get_driver_dashboard_earnings_applies_five_percent_commission(): void
    {
        $driver = $this->makeUser();
        $ride   = $this->insertRide($driver->id, ['price_per_seat' => 50000]);
        $this->makeBooking($ride, $this->makeUser(), 2, 'completed');
        // 2 seats * 50000 * 0.95 = 95000

        $this->assertEquals(95000.0, $this->service->getDriverDashboard($driver->id)['stats']['total_earnings']);
    }

    public function test_get_driver_dashboard_earnings_sum_across_multiple_completed_bookings(): void
    {
        $driver = $this->makeUser();
        $ride1  = $this->insertRide($driver->id, ['price_per_seat' => 50000]);
        $ride2  = $this->insertRide($driver->id, ['price_per_seat' => 20000]);
        $this->makeBooking($ride1, $this->makeUser(), 1, 'completed'); // 47500
        $this->makeBooking($ride2, $this->makeUser(), 3, 'completed'); // 57000

        $this->assertEquals(104500.0, $this->service->getDriverDashboard($driver->id)['stats']['total_earnings']);
    }

    public function test_get_driver_dashboard_earnings_ignores_other_drivers_rides(): void
    {
        $driver      = $this->makeUser();
        $otherDriver = $this->makeUser();
        $ownRide     = $this->insertRide($driver->id, ['price_per_seat' => 50000]);
        $otherRide   = $this->insertRide($otherDriver->id, ['price_per_seat' => 50000]);

        $this->makeBooking($ownRide, $this->makeUser(), 1, 'completed');
        $this->makeBooking($otherRide, $this->makeUser(), 1, 'completed');

        $this->assertEquals(47500.0, $this->service->getDriverDashboard($driver->id)['stats']['total_earnings']);
    }

    public function test_get_driver_dashboard_recent_rides_limited_to_ten(): void
    {
        $driver = $this->makeUser();
        for ($i = 0; $i < 12; $i++) {
            $this->insertRide($driver->id, ['created_at' => now()->subMinutes($i)]);
        }

        $this->assertCount(10, $this->service->getDriverDashboard($driver->id)['recent_rides']);
    }

    public function test_get_driver_dashboard_recent_rides_ordered_newest_first(): void
    {
        $driver = $this->makeUser();
        $older  = $this->insertRide($driver->id, ['created_at' => now()->subDays(2)]);
        $newer  = $this->insertRide($driver->id, ['created_at' => now()]);

        $recentRides = $this->service->getDriverDashboard($driver->id)['recent_rides'];

        $this->assertEquals($newer->id, $recentRides[0]['id']);
        $this->assertEquals($older->id, $recentRides[1]['id']);
    }

    public function test_get_driver_dashboard_recent_rides_shape_excludes_ratings_and_comments(): void
    {
        $driver = $this->makeUser();
        $this->insertRide($driver->id, [
            'pickup_address'      => 'Damascus',
            'destination_address' => 'Aleppo',
            'price_per_seat'      => 30000,
        ]);

        $ride = $this->service->getDriverDashboard($driver->id)['recent_rides'][0];

        $this->assertEquals('Damascus', $ride['source']);
        $this->assertEquals('Aleppo', $ride['destination']);
        $this->assertEquals(30000.0, $ride['price_per_seat']);
        $this->assertArrayNotHasKey('rating', $ride);
        $this->assertArrayNotHasKey('comment', $ride);
    }

    public function test_get_driver_dashboard_favorite_destination_is_null_without_finished_rides(): void
    {
        $driver = $this->makeUser();
        $this->insertRide($driver->id, ['status' => 'active', 'destination_address' => 'Homs']);

        $this->assertNull($this->service->getDriverDashboard($driver->id)['favorite_destination']);
    }

    public function test_get_driver_dashboard_favorite_destination_picks_most_visited(): void
    {
        $driver = $this->makeUser();
        $this->insertRide($driver->id, ['status' => 'finished', 'destination_address' => 'Homs']);
        $this->insertRide($driver->id, ['status' => 'finished', 'destination_address' => 'Homs']);
        $this->insertRide($driver->id, ['status' => 'finished', 'destination_address' => 'Aleppo']);

        $favorite = $this->service->getDriverDashboard($driver->id)['favorite_destination'];

        $this->assertEquals('Homs', $favorite['name']);
        $this->assertEquals(2, $favorite['visit_count']);
    }

    public function test_get_driver_dashboard_favorite_destination_ignores_non_finished_rides(): void
    {
        $driver = $this->makeUser();
        $this->insertRide($driver->id, ['status' => 'finished', 'destination_address' => 'Homs']);
        $this->insertRide($driver->id, ['status' => 'cancelled', 'destination_address' => 'Aleppo']);
        $this->insertRide($driver->id, ['status' => 'cancelled', 'destination_address' => 'Aleppo']);

        $this->assertEquals('Homs', $this->service->getDriverDashboard($driver->id)['favorite_destination']['name']);
    }

    public function test_get_driver_dashboard_documents_shape(): void
    {
        $driver = $this->makeUser();
        Photo::create(['user_id' => $driver->id, 'type' => 'license', 'path' => 'verifications/license/x.jpg']);

        $documents = $this->service->getDriverDashboard($driver->id)['documents'];

        $this->assertCount(1, $documents);
        $this->assertEquals('license', $documents[0]['type']);
        $this->assertStringContainsString('x.jpg', $documents[0]['file_url']);
    }

    public function test_get_driver_dashboard_vehicle_info(): void
    {
        $driver = $this->makeUser();
        $driver->profile->update([
            'type_of_car'     => 'Hyundai Elantra',
            'color_of_car'    => 'Silver',
            'number_of_seats' => 4,
            'car_pic'         => 'cars/hyundai.jpg',
        ]);

        $vehicle = $this->service->getDriverDashboard($driver->id)['vehicle'];

        $this->assertEquals('Hyundai Elantra', $vehicle['type']);
        $this->assertEquals('Silver', $vehicle['color']);
        $this->assertEquals(4, $vehicle['seats']);
        $this->assertStringContainsString('hyundai.jpg', $vehicle['photo_url']);
    }

    public function test_get_driver_dashboard_rating_defaults_when_no_ratings_exist(): void
    {
        $driver = $this->makeUser();

        $rating = $this->service->getDriverDashboard($driver->id)['rating'];

        $this->assertEquals(0, $rating['average']);
        $this->assertEquals(0, $rating['total_ratings']);
    }

    public function test_get_driver_dashboard_rating_reflects_submitted_ratings(): void
    {
        $driver = $this->makeUser();
        $this->rate($this->makeUser(), $driver, 5.0);
        $this->rate($this->makeUser(), $driver, 3.0);

        $rating = $this->service->getDriverDashboard($driver->id)['rating'];

        $this->assertEquals(4.0, $rating['average']);
        $this->assertEquals(2, $rating['total_ratings']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // getRecentActivity
    // ═══════════════════════════════════════════════════════════════════════

    public function test_get_recent_activity_returns_empty_array_when_nothing_happened(): void
    {
        $this->assertEquals([], $this->service->getRecentActivity());
    }

    /**
     * @dataProvider verificationEventProvider
     */
    public function test_get_recent_activity_verification_event_shape(
        string $status,
        string $expectedType,
        string $expectedIcon,
        string $expectedColor,
        string $expectedActor,
        string $expectedMessageFragment
    ): void {
        $applicant = $this->makeUser([
            'first_name'          => 'Zaid',
            'last_name'           => 'Alzoubi',
            'verification_status' => $status,
            'is_verified_driver'  => $status === 'approved',
        ]);
        $this->attachDriverDocs($applicant);

        $activity = $this->service->getRecentActivity();

        $this->assertCount(1, $activity);
        $this->assertEquals($expectedType, $activity[0]['type']);
        $this->assertEquals($expectedIcon, $activity[0]['icon']);
        $this->assertEquals($expectedColor, $activity[0]['color']);
        $this->assertEquals($expectedActor, $activity[0]['actor']);
        $this->assertStringContainsString($expectedMessageFragment, $activity[0]['message']);
    }

    public static function verificationEventProvider(): array
    {
        return [
            'pending'  => ['pending',  'verification_pending',  'clock', 'blue',  'Zaid Alzoubi', 'submitted by'],
            'approved' => ['approved', 'verification_approved', 'check', 'green', 'Admin',         'accepted'],
            'rejected' => ['rejected', 'verification_rejected', 'x',     'red',   'Admin',         'rejected'],
        ];
    }

    public function test_get_recent_activity_excludes_applicants_without_documents(): void
    {
        $this->makeUser(['verification_status' => 'pending']);

        $this->assertEmpty($this->service->getRecentActivity());
    }

    public function test_get_recent_activity_excludes_users_with_no_verification_status(): void
    {
        $user = $this->makeUser(['verification_status' => 'none']);
        $this->attachDriverDocs($user);

        $this->assertEmpty($this->service->getRecentActivity());
    }

    public function test_get_recent_activity_includes_vehicle_update_event(): void
    {
        $driver = $this->makeUser(['first_name' => 'Layla', 'last_name' => 'Saab', 'is_verified_driver' => true]);
        $driver->profile->update(['type_of_car' => 'Kia Sportage']);

        $activity = $this->service->getRecentActivity();

        $this->assertCount(1, $activity);
        $this->assertEquals('vehicle_update', $activity[0]['type']);
        $this->assertEquals('edit', $activity[0]['icon']);
        $this->assertEquals('purple', $activity[0]['color']);
        $this->assertStringContainsString('Layla Saab', $activity[0]['message']);
    }

    public function test_get_recent_activity_vehicle_update_requires_type_of_car(): void
    {
        $this->makeUser(['is_verified_driver' => true]); // profile exists but type_of_car is null

        $this->assertEmpty($this->service->getRecentActivity());
    }

    public function test_get_recent_activity_vehicle_update_requires_driver_or_documents(): void
    {
        $plainUser = $this->makeUser(['is_verified_driver' => false]);
        $plainUser->profile->update(['type_of_car' => 'Kia Sportage']);

        $this->assertEmpty($this->service->getRecentActivity());
    }

    public function test_get_recent_activity_vehicle_update_allows_pending_applicant_with_documents(): void
    {
        $applicant = $this->makeUser(['verification_status' => 'none', 'is_verified_driver' => false]);
        $this->attachDriverDocs($applicant);
        $applicant->profile->update(['type_of_car' => 'Kia Sportage']);

        $activity = $this->service->getRecentActivity();

        $this->assertCount(1, $activity);
        $this->assertEquals('vehicle_update', $activity[0]['type']);
    }

    public function test_get_recent_activity_sorts_events_newest_first(): void
    {
        $older = $this->makeUser(['verification_status' => 'pending', 'first_name' => 'Old']);
        $this->attachDriverDocs($older);
        $this->forceTimestamp('users', $older->id, 'updated_at', now()->subDays(3));

        $newer = $this->makeUser(['verification_status' => 'pending', 'first_name' => 'New']);
        $this->attachDriverDocs($newer);
        $this->forceTimestamp('users', $newer->id, 'updated_at', now());

        $activity = $this->service->getRecentActivity();

        $this->assertEquals($newer->id, $activity[0]['user_id']);
        $this->assertEquals($older->id, $activity[1]['user_id']);
    }

    public function test_get_recent_activity_respects_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $applicant = $this->makeUser(['verification_status' => 'pending']);
            $this->attachDriverDocs($applicant);
        }

        $this->assertCount(2, $this->service->getRecentActivity(2));
    }

    public function test_get_recent_activity_human_time_shows_minutes_for_recent_events(): void
    {
        $applicant = $this->makeUser(['verification_status' => 'pending']);
        $this->attachDriverDocs($applicant);
        $this->forceTimestamp('users', $applicant->id, 'updated_at', now()->subMinutes(15));

        $this->assertStringContainsString('mins ago', $this->service->getRecentActivity()[0]['human_time']);
    }

    public function test_get_recent_activity_human_time_shows_hours_for_older_events(): void
    {
        $applicant = $this->makeUser(['verification_status' => 'pending']);
        $this->attachDriverDocs($applicant);
        $this->forceTimestamp('users', $applicant->id, 'updated_at', now()->subHours(5));

        $this->assertStringContainsString('hours ago', $this->service->getRecentActivity()[0]['human_time']);
    }

    public function test_get_recent_activity_human_time_shows_date_for_events_older_than_a_day(): void
    {
        $applicant = $this->makeUser(['verification_status' => 'pending']);
        $this->attachDriverDocs($applicant);
        $time = now()->subDays(3);
        $this->forceTimestamp('users', $applicant->id, 'updated_at', $time);

        $this->assertEquals($time->format('d M Y'), $this->service->getRecentActivity()[0]['human_time']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // getVerificationEfficiency
    // ═══════════════════════════════════════════════════════════════════════

    public function test_get_verification_efficiency_defaults_to_week(): void
    {
        $result = $this->service->getVerificationEfficiency();

        $this->assertEquals('week', $result['period']);
        $this->assertEquals('Week', $result['period_label']);
    }

    public function test_get_verification_efficiency_current_window_matches_resolved_week_bounds(): void
    {
        $result = $this->service->getVerificationEfficiency('week');

        $this->assertEquals($this->frozenNow->copy()->startOfWeek()->toDateTimeString(), $result['current']['start']);
        $this->assertEquals($this->frozenNow->copy()->endOfWeek()->toDateTimeString(), $result['current']['end']);
    }

    public function test_get_verification_efficiency_is_100_percent_when_nothing_came_in(): void
    {
        $current = $this->service->getVerificationEfficiency('week')['current'];

        $this->assertEquals(100, $current['efficiency_pct']);
        $this->assertEquals(0, $current['total_incoming']);
        $this->assertEquals(0, $current['processed']);
        $this->assertEquals(0, $current['pending']);
    }

    public function test_get_verification_efficiency_calculates_percentage_from_processed_over_incoming(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $user = $this->makeUser(['verification_status' => 'approved', 'is_verified_driver' => true]);
            $this->attachDriverDocs($user);
            $this->forceTimestamp('users', $user->id, 'updated_at', now()->subHours(1));
        }

        $stillPending = $this->makeUser(['verification_status' => 'pending']);
        $this->attachDriverDocs($stillPending);

        // 4 incoming this week, 3 processed -> 75%
        $current = $this->service->getVerificationEfficiency('week')['current'];

        $this->assertEquals(4, $current['total_incoming']);
        $this->assertEquals(3, $current['processed']);
        $this->assertEquals(1, $current['pending']);
        $this->assertEquals(75, $current['efficiency_pct']);
    }

    public function test_get_verification_efficiency_incoming_counts_document_upload_regardless_of_status(): void
    {
        $user = $this->makeUser(['verification_status' => 'none']);
        $this->attachDriverDocs($user);

        $this->assertEquals(1, $this->service->getVerificationEfficiency('week')['current']['total_incoming']);
    }

    public function test_get_verification_efficiency_processed_requires_approved_or_rejected_status(): void
    {
        $pendingUser = $this->makeUser(['verification_status' => 'pending']);
        $this->attachDriverDocs($pendingUser);

        $current = $this->service->getVerificationEfficiency('week')['current'];

        $this->assertEquals(1, $current['total_incoming']);
        $this->assertEquals(0, $current['processed']);
    }

    public function test_get_verification_efficiency_delta_positive_trends_up(): void
    {
        // This week: 1 incoming, 1 processed -> 100%
        $currentUser = $this->makeUser(['verification_status' => 'approved']);
        $this->attachDriverDocs($currentUser);
        $this->forceTimestamp('users', $currentUser->id, 'updated_at', now()->subHours(1));

        // Last week: 2 incoming, 1 processed -> 50%
        $lastWeekApproved = $this->makeUser(['verification_status' => 'approved']);
        $this->attachDriverDocsAt($lastWeekApproved, now()->subWeek());
        $this->forceTimestamp('users', $lastWeekApproved->id, 'updated_at', now()->subWeek());

        $lastWeekPending = $this->makeUser(['verification_status' => 'pending']);
        $this->attachDriverDocsAt($lastWeekPending, now()->subWeek());

        $result = $this->service->getVerificationEfficiency('week');

        $this->assertEquals(100, $result['current']['efficiency_pct']);
        $this->assertEquals(50, $result['previous']['efficiency_pct']);
        $this->assertEquals(50, $result['comparison']['delta']);
        $this->assertEquals('+50%', $result['comparison']['delta_display']);
        $this->assertEquals('up', $result['comparison']['trend']);
        $this->assertStringContainsString('higher than last week', $result['comparison']['text']);
    }

    public function test_get_verification_efficiency_delta_negative_trends_down(): void
    {
        // This week: 2 incoming, 1 processed -> 50%
        $thisWeekProcessed = $this->makeUser(['verification_status' => 'rejected']);
        $this->attachDriverDocs($thisWeekProcessed);
        $this->forceTimestamp('users', $thisWeekProcessed->id, 'updated_at', now()->subHours(1));

        $this->attachDriverDocs($this->makeUser(['verification_status' => 'pending']));

        // Last week: 1 incoming, 1 processed -> 100%
        $lastWeekProcessed = $this->makeUser(['verification_status' => 'approved']);
        $this->attachDriverDocsAt($lastWeekProcessed, now()->subWeek());
        $this->forceTimestamp('users', $lastWeekProcessed->id, 'updated_at', now()->subWeek());

        $result = $this->service->getVerificationEfficiency('week');

        $this->assertEquals(50, $result['current']['efficiency_pct']);
        $this->assertEquals(100, $result['previous']['efficiency_pct']);
        $this->assertEquals(-50, $result['comparison']['delta']);
        $this->assertEquals('-50%', $result['comparison']['delta_display']);
        $this->assertEquals('down', $result['comparison']['trend']);
        $this->assertStringContainsString('lower than last week', $result['comparison']['text']);
    }

    public function test_get_verification_efficiency_equal_periods_are_flat(): void
    {
        // Nothing in either window -> both 100%, delta 0
        $result = $this->service->getVerificationEfficiency('week');

        $this->assertEquals(0, $result['comparison']['delta']);
        $this->assertEquals('+0%', $result['comparison']['delta_display']);
        $this->assertEquals('flat', $result['comparison']['trend']);
        $this->assertEquals('Same as last week', $result['comparison']['text']);
    }

    public function test_get_verification_efficiency_day_period_uses_today_and_yesterday_boundaries(): void
    {
        $today = $this->makeUser(['verification_status' => 'approved']);
        $this->attachDriverDocs($today);
        $this->forceTimestamp('users', $today->id, 'updated_at', now()->subHours(1));

        $yesterday = $this->makeUser(['verification_status' => 'approved']);
        $this->attachDriverDocsAt($yesterday, now()->subDay());
        $this->forceTimestamp('users', $yesterday->id, 'updated_at', now()->subDay());

        $result = $this->service->getVerificationEfficiency('day');

        $this->assertEquals(1, $result['current']['total_incoming']);
        $this->assertEquals(1, $result['current']['processed']);
        $this->assertEquals(1, $result['previous']['total_incoming']);
        $this->assertEquals(1, $result['previous']['processed']);
        $this->assertEquals('Day', $result['period_label']);
        $this->assertEquals('Last day', $result['previous']['label']);
    }

    public function test_get_verification_efficiency_month_period_uses_this_and_last_month_boundaries(): void
    {
        $thisMonth = $this->makeUser(['verification_status' => 'approved']);
        $this->attachDriverDocs($thisMonth);
        $this->forceTimestamp('users', $thisMonth->id, 'updated_at', now()->subHours(1));

        $lastMonth = $this->makeUser(['verification_status' => 'approved']);
        $this->attachDriverDocsAt($lastMonth, now()->subMonth());
        $this->forceTimestamp('users', $lastMonth->id, 'updated_at', now()->subMonth());

        $result = $this->service->getVerificationEfficiency('month');

        $this->assertEquals(1, $result['current']['total_incoming']);
        $this->assertEquals(1, $result['previous']['total_incoming']);
        $this->assertEquals('Month', $result['period_label']);
    }

    public function test_get_verification_efficiency_unrecognized_period_falls_back_to_week_bounds(): void
    {
        $result = $this->service->getVerificationEfficiency('year');

        // 'period' echoes back whatever the caller passed...
        $this->assertEquals('year', $result['period']);
        // ...but resolvePeriodBounds() silently treats anything that isn't
        // 'day' or 'month' as a week-length window.
        $this->assertEquals('Week', $result['period_label']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // getAdminPhoto
    // ═══════════════════════════════════════════════════════════════════════

    public function test_get_admin_photo_returns_null_when_no_id_given(): void
    {
        $this->assertNull($this->service->getAdminPhoto(null));
    }

    public function test_get_admin_photo_treats_zero_id_as_absent(): void
    {
        $this->assertNull($this->service->getAdminPhoto(0));
    }

    public function test_get_admin_photo_returns_null_when_profile_does_not_exist(): void
    {
        $this->assertNull($this->service->getAdminPhoto(999999));
    }

    public function test_get_admin_photo_returns_null_when_profile_has_no_photo(): void
    {
        $admin = $this->makeUser();
        $admin->profile->update(['profile_photo' => null]);

        $this->assertNull($this->service->getAdminPhoto($admin->id));
    }

    public function test_get_admin_photo_returns_asset_url_when_photo_present(): void
    {
        $admin = $this->makeUser();
        $admin->profile->update(['profile_photo' => 'profiles/profile_photo/admin.jpg']);

        $this->assertStringContainsString('admin.jpg', $this->service->getAdminPhoto($admin->id));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * UserObserver auto-creates a Profile (with a default, non-null
     * profile_photo) and — were the system_admin email actually configured —
     * would also auto-seed a rating. See class docblock.
     */
    private function makeUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['status' => 1], $attrs));
    }

    private function reloadDriver(int $id): User
    {
        return User::with('profile')->findOrFail($id);
    }

    /** Attach license/mechanic_card style documents so a user counts as a driver applicant. */
    private function attachDriverDocs(User $user, array $types = ['license', 'mechanic_card']): void
    {
        foreach ($types as $type) {
            Photo::create(['user_id' => $user->id, 'type' => $type, 'path' => "verifications/{$type}/test.jpg"]);
        }
    }

    /** Same as attachDriverDocs(), but backdates the photo's created_at for period-boundary tests. */
    private function attachDriverDocsAt(User $user, Carbon $time, array $types = ['license', 'mechanic_card']): void
    {
        foreach ($types as $type) {
            $photo = Photo::create(['user_id' => $user->id, 'type' => $type, 'path' => "verifications/{$type}/test.jpg"]);
            $this->forceTimestamp('photos', $photo->id, 'created_at', $time);
        }
    }

    private function rate(User $rater, User $rated, float $rating): UserRating
    {
        return UserRating::create([
            'rater_id'      => $rater->id,
            'rated_user_id' => $rated->id,
            'rating'        => $rating,
        ]);
    }

    /** Force a timestamp column via the query builder, bypassing Eloquent's auto-touch. */
    private function forceTimestamp(string $table, int $id, string $column, Carbon $time): void
    {
        DB::table($table)->where('id', $id)->update([$column => $time]);
    }

    /**
     * Insert a ride via raw SQL — Ride's pickup/destination columns are
     * spatial POINTs, so this mirrors the pattern used across the Ride test
     * suites rather than going through Eloquent's location mutators.
     */
    private function insertRide(int $driverId, array $overrides = []): Ride
    {
        $status              = $overrides['status']              ?? 'active';
        $pickupAddress       = $overrides['pickup_address']       ?? 'دمشق';
        $destinationAddress  = $overrides['destination_address']  ?? 'حلب';
        $pricePerSeat        = $overrides['price_per_seat']       ?? 50000;
        $communicationNumber = array_key_exists('communication_number', $overrides)
            ? $overrides['communication_number']
            : '0912345678';
        $departureTime = $overrides['departure_time'] ?? now()->addHours(3);
        $createdAt     = $overrides['created_at']     ?? now();

        $departureStr = $departureTime instanceof Carbon ? $departureTime->format('Y-m-d H:i:s') : $departureTime;
        $createdStr   = $createdAt instanceof Carbon ? $createdAt->format('Y-m-d H:i:s') : $createdAt;

        DB::statement("
            INSERT INTO rides (
                driver_id, pickup_address, destination_address,
                pickup_location, destination_location,
                departure_time, available_seats, price_per_seat,
                payment_method, booking_type, status,
                distance, duration, communication_number, created_at, updated_at
            ) VALUES (
                ?, ?, ?,
                ST_GeomFromText('POINT(33.5138 36.2765)', 4326), ST_GeomFromText('POINT(36.2021 37.1343)', 4326),
                ?, 4, ?,
                'cash', 'direct', ?,
                320.5, 240, ?, ?, ?
            )
        ", [
            $driverId, $pickupAddress, $destinationAddress,
            $departureStr, $pricePerSeat,
            $status,
            $communicationNumber, $createdStr, $createdStr,
        ]);

        return Ride::latest('id')->first();
    }

    private function makeBooking(Ride $ride, User $passenger, int $seats = 1, string $status = 'completed'): Booking
    {
        return Booking::create([
            'user_id'              => $passenger->id,
            'ride_id'              => $ride->id,
            'seats'                => $seats,
            'status'               => $status,
            'communication_number' => '0911111111',
        ]);
    }
}
