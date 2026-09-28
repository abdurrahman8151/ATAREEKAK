<?php

namespace Tests\Feature\Staff;

use App\Enums\StaffRole;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * StaffOperationsControllerTest
 *
 * UC-ADM-05: Support agents browse operational data in read-only mode.
 *
 * Routes under test (all behind `staff` middleware):
 *   GET   /api/staff/users                      → users()
 *   GET   /api/staff/users/{userId}             → userProfile()
 *   GET   /api/staff/trips                      → trips()
 *   GET   /api/staff/bookings                   → bookings()
 *   POST  /api/staff/trips/{rideId}/cancel      → cancelTrip()
 *   POST  /api/staff/bookings/{bookingId}/cancel → cancelBooking()
 */
class StaffOperationsControllerTest extends TestCase
{
    use RefreshDatabase;

    private Employee $agent;

    private string $agentToken;

    private User $driver;

    private User $passenger;

    private string $driverPhone;

    private string $passengerPhone;

    protected function setUp(): void
    {
        parent::setUp();

        // Unique phone numbers prevent duplicate-key errors across tests.
        $this->driverPhone = '091'.rand(1000000, 9999999);
        $this->passengerPhone = '092'.rand(1000000, 9999999);

        $this->agent = $this->makeEmployee(
            StaffRole::SUPPORT_AGENT,
            'ops_agent@test.test',
            'ops_agent_1'
        );
        $this->agentToken = $this->getStaffToken('ops_agent@test.test', 'password123');

        $this->driver = User::factory()->create([
            'is_verified_driver' => true,
            'is_verified_passenger' => true,
            'verification_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        $this->passenger = User::factory()->create([
            'is_verified_passenger' => true,
            'verification_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        $this->seedAdminWallets();

        $dw = Wallet::create([
            'user_id' => $this->driver->id,
            'phone_number' => $this->driverPhone,
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 0,
        ]);
        $this->driver->update(['wallet_id' => $dw->id]);

        $pw = Wallet::create([
            'user_id' => $this->passenger->id,
            'phone_number' => $this->passengerPhone,
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 0,
        ]);
        $this->passenger->update(['wallet_id' => $pw->id]);
    }

    // =========================================================================
    // users()   GET /api/staff/users
    // =========================================================================

    public function test_users_returns_200_with_success_status(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/users')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_users_requires_authentication(): void
    {
        $this->getJson('/api/staff/users')->assertStatus(401);
    }

    public function test_users_response_does_not_expose_admin_photo_block(): void
    {
        $response = $this->withToken($this->agentToken)->getJson('/api/staff/users');

        $response->assertStatus(200);
        // AdminUserController injects admin_photo; staff view strips it.
        $this->assertArrayNotHasKey('admin_photo', $response->json('data'));
    }

    public function test_users_returns_paginated_stats_and_users(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/users')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['stats', 'users', 'meta']]);
    }

    public function test_users_accepts_driver_type_filter(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/users?type=driver')
            ->assertStatus(200);
    }

    public function test_users_accepts_passenger_type_filter(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/users?type=passenger')
            ->assertStatus(200);
    }

    public function test_users_accepts_verified_status_filter(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/users?status=verified')
            ->assertStatus(200);
    }

    public function test_users_accepts_pending_status_filter(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/users?status=pending')
            ->assertStatus(200);
    }

    public function test_users_accepts_suspended_status_filter(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/users?status=suspended')
            ->assertStatus(200);
    }

    public function test_users_accepts_search_parameter(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/users?search=Ahmad')
            ->assertStatus(200);
    }

    public function test_users_accepts_date_filter(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/users?date=last_30_days')
            ->assertStatus(200);
    }

    public function test_users_rejects_invalid_type_filter(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/users?type=invalid_type')
            ->assertStatus(422);
    }

    public function test_users_rejects_invalid_status_filter(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/users?status=invalid_status')
            ->assertStatus(422);
    }

    public function test_users_rejects_invalid_date_filter(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/users?date=last_99_years')
            ->assertStatus(422);
    }

    public function test_users_respects_per_page_parameter(): void
    {
        // Create several users so there's data to paginate.
        User::factory()->count(5)->create();

        $response = $this->withToken($this->agentToken)
            ->getJson('/api/staff/users?per_page=5');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['meta' => ['per_page']]]);

        $this->assertEquals(5, $response->json('data.meta.per_page'));
    }

    public function test_users_search_returns_matching_user(): void
    {
        User::factory()->create(['first_name' => 'UniqueFirstName99']);

        $response = $this->withToken($this->agentToken)
            ->getJson('/api/staff/users?search=UniqueFirstName99');

        $response->assertStatus(200);
        $users = $response->json('data.users');
        $this->assertNotEmpty($users);
    }

    // =========================================================================
    // userProfile()   GET /api/staff/users/{userId}
    // =========================================================================

    public function test_user_profile_returns_200_for_existing_user(): void
    {
        $this->withToken($this->agentToken)
            ->getJson("/api/staff/users/{$this->driver->id}")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_user_profile_requires_authentication(): void
    {
        $this->getJson("/api/staff/users/{$this->driver->id}")
            ->assertStatus(401);
    }

    public function test_user_profile_returns_404_for_nonexistent_user(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/users/999999')
            ->assertStatus(404);
    }

    public function test_user_profile_contains_identity_fields(): void
    {
        $this->withToken($this->agentToken)
            ->getJson("/api/staff/users/{$this->driver->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'full_name',
                    'email',
                    'verification_status',
                    'account_status',
                ],
            ]);
    }

    public function test_user_profile_contains_score_block(): void
    {
        $this->withToken($this->agentToken)
            ->getJson("/api/staff/users/{$this->driver->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'score' => [
                        'score',
                        'tier',
                        'cancel_rate',
                        'total_rides',
                        'total_cancellations',
                    ],
                ],
            ]);
    }

    public function test_user_profile_contains_rating_block(): void
    {
        $this->withToken($this->agentToken)
            ->getJson("/api/staff/users/{$this->driver->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'rating' => ['average', 'total_ratings'],
                ],
            ]);
    }

    public function test_user_profile_contains_ride_history_split_by_role(): void
    {
        $this->withToken($this->agentToken)
            ->getJson("/api/staff/users/{$this->driver->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'ride_history' => [
                        'as_driver' => ['total', 'completed', 'cancelled'],
                        'as_passenger' => ['total', 'completed', 'cancelled'],
                    ],
                ],
            ]);
    }

    public function test_user_profile_full_name_is_correctly_trimmed(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ahmad',
            'last_name' => 'Alzoubi',
        ]);

        $response = $this->withToken($this->agentToken)
            ->getJson("/api/staff/users/{$user->id}");

        $response->assertStatus(200);
        $this->assertEquals('Ahmad Alzoubi', $response->json('data.full_name'));
    }

    public function test_user_profile_returns_comments_received(): void
    {
        $this->withToken($this->agentToken)
            ->getJson("/api/staff/users/{$this->driver->id}")
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['comments_received']]);
    }

    public function test_user_profile_account_status_is_active_for_active_user(): void
    {
        $this->driver->update(['status' => 1]);

        $response = $this->withToken($this->agentToken)
            ->getJson("/api/staff/users/{$this->driver->id}");

        $response->assertStatus(200);
        $this->assertEquals('active', $response->json('data.account_status'));
    }

    // =========================================================================
    // trips()   GET /api/staff/trips
    // =========================================================================

    public function test_trips_returns_200_with_success_status(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/trips')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data', 'meta', 'counts']);
    }

    public function test_trips_requires_authentication(): void
    {
        $this->getJson('/api/staff/trips')->assertStatus(401);
    }

    public function test_trips_returns_empty_list_when_no_rides_exist(): void
    {
        $response = $this->withToken($this->agentToken)->getJson('/api/staff/trips');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_trips_returns_inserted_ride_in_list(): void
    {
        $this->insertRide();

        $response = $this->withToken($this->agentToken)->getJson('/api/staff/trips');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_trips_filter_all_returns_every_ride(): void
    {
        $this->insertRide(['status' => 'active']);
        $this->insertRide(['status' => 'finished']);
        $this->insertRide(['status' => 'cancelled']);

        $response = $this->withToken($this->agentToken)
            ->getJson('/api/staff/trips?filter=all');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_trips_filter_completed_returns_only_finished_rides(): void
    {
        $this->insertRide(['status' => 'finished']);
        $this->insertRide(['status' => 'active']);

        $response = $this->withToken($this->agentToken)
            ->getJson('/api/staff/trips?filter=completed');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_trips_filter_cancelled_returns_only_cancelled_rides(): void
    {
        $this->insertRide(['status' => 'cancelled']);
        $this->insertRide(['status' => 'cancelled']);
        $this->insertRide(['status' => 'active']);

        $response = $this->withToken($this->agentToken)
            ->getJson('/api/staff/trips?filter=cancelled');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_trips_filter_awaiting_returns_awaiting_confirmation_rides(): void
    {
        $this->insertRide(['status' => 'awaiting_confirmation']);
        $this->insertRide(['status' => 'active']);

        $response = $this->withToken($this->agentToken)
            ->getJson('/api/staff/trips?filter=awaiting');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_trips_filter_active_excludes_scheduled_and_finished(): void
    {
        // "active" filter = status active AND departure <= now
        $this->insertRide(['status' => 'active', 'departure_time' => now()->subMinutes(5)]);
        $this->insertRide(['status' => 'finished']);

        $response = $this->withToken($this->agentToken)
            ->getJson('/api/staff/trips?filter=active');

        $response->assertStatus(200);
        // Only the past-departure active ride should appear.
        $this->assertCount(1, $response->json('data'));
    }

    public function test_trips_rejects_invalid_filter_value(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/trips?filter=not_valid')
            ->assertStatus(422);
    }

    public function test_trips_returns_status_counts_in_response(): void
    {
        $this->insertRide(['status' => 'active']);
        $this->insertRide(['status' => 'cancelled']);

        $this->withToken($this->agentToken)
            ->getJson('/api/staff/trips')
            ->assertStatus(200)
            ->assertJsonStructure(['counts']);
    }

    public function test_trips_respects_per_page_parameter(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->insertRide();
        }

        $response = $this->withToken($this->agentToken)
            ->getJson('/api/staff/trips?per_page=2');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $this->assertEquals(5, $response->json('meta.total'));
    }

    public function test_trips_returns_correct_pagination_meta(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/trips')
            ->assertStatus(200)
            ->assertJsonStructure([
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    // =========================================================================
    // bookings()   GET /api/staff/bookings
    // =========================================================================

    public function test_bookings_returns_200_with_success_status(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/bookings')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_bookings_requires_authentication(): void
    {
        $this->getJson('/api/staff/bookings')->assertStatus(401);
    }

    public function test_bookings_returns_empty_list_when_no_bookings_exist(): void
    {
        $response = $this->withToken($this->agentToken)->getJson('/api/staff/bookings');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_bookings_returns_existing_bookings(): void
    {
        $ride = $this->insertRide();
        $this->makeBooking($ride, 'confirmed');

        $response = $this->withToken($this->agentToken)->getJson('/api/staff/bookings');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_bookings_filters_by_status_confirmed(): void
    {
        $ride = $this->insertRide();
        $this->makeBooking($ride, 'confirmed');
        $this->makeBooking($ride, 'cancelled');

        $response = $this->withToken($this->agentToken)
            ->getJson('/api/staff/bookings?status=confirmed');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_bookings_filters_by_status_pending(): void
    {
        $ride = $this->insertRide();
        $this->makeBooking($ride, 'pending');
        $this->makeBooking($ride, 'confirmed');

        $response = $this->withToken($this->agentToken)
            ->getJson('/api/staff/bookings?status=pending');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_bookings_filters_by_user_id(): void
    {
        $ride = $this->insertRide();
        $otherUser = User::factory()->create();
        $this->makeBooking($ride, 'confirmed', $this->passenger);
        $this->makeBooking($ride, 'confirmed', $otherUser);

        $response = $this->withToken($this->agentToken)
            ->getJson("/api/staff/bookings?user_id={$this->passenger->id}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_bookings_filters_by_ride_id(): void
    {
        $ride1 = $this->insertRide();
        $ride2 = $this->insertRide();
        $this->makeBooking($ride1, 'confirmed');
        $this->makeBooking($ride2, 'confirmed');

        $response = $this->withToken($this->agentToken)
            ->getJson("/api/staff/bookings?ride_id={$ride1->id}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_bookings_rejects_invalid_status_value(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/bookings?status=invalid_status')
            ->assertStatus(422);
    }

    public function test_bookings_response_contains_passenger_and_ride_info(): void
    {
        $ride = $this->insertRide();
        $this->makeBooking($ride, 'confirmed');

        $this->withToken($this->agentToken)
            ->getJson('/api/staff/bookings')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'status', 'seats', 'passenger', 'ride', 'total_price', 'booked_at'],
                ],
            ]);
    }

    public function test_bookings_response_nested_passenger_includes_name_and_email(): void
    {
        $ride = $this->insertRide();
        $this->makeBooking($ride, 'confirmed');

        $this->withToken($this->agentToken)
            ->getJson('/api/staff/bookings')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['passenger' => ['id', 'name', 'email']],
                ],
            ]);
    }

    public function test_bookings_total_price_equals_seats_times_price_per_seat(): void
    {
        $ride = $this->insertRide(['price_per_seat' => 50000]);
        $booking = $this->makeBooking($ride, 'confirmed', seats: 2);

        $response = $this->withToken($this->agentToken)->getJson('/api/staff/bookings');

        $response->assertStatus(200);
        $item = collect($response->json('data'))->firstWhere('id', $booking->id);
        $this->assertEquals(100000, $item['total_price']);
    }

    public function test_bookings_respects_per_page_parameter(): void
    {
        $ride = $this->insertRide();
        for ($i = 0; $i < 5; $i++) {
            $this->makeBooking($ride, 'confirmed');
        }

        $response = $this->withToken($this->agentToken)
            ->getJson('/api/staff/bookings?per_page=2');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $this->assertEquals(5, $response->json('meta.total'));
    }

    // =========================================================================
    // cancelTrip()   POST /api/staff/trips/{rideId}/cancel
    // =========================================================================

    public function test_cancel_trip_sets_ride_status_to_cancelled(): void
    {
        $ride = $this->insertRide(['status' => 'active']);

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/trips/{$ride->id}/cancel", [
                'reason' => 'Trip cancelled by support for safety reasons.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('rides', [
            'id' => $ride->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_cancel_trip_also_cancels_confirmed_bookings(): void
    {
        $ride = $this->insertRide(['status' => 'active']);
        $booking = $this->makeBooking($ride, 'confirmed');

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/trips/{$ride->id}/cancel", [
                'reason' => 'Trip cancelled — all passengers will be notified.',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_cancel_trip_also_cancels_pending_bookings(): void
    {
        $ride = $this->insertRide(['status' => 'active']);
        $booking = $this->makeBooking($ride, 'pending');

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/trips/{$ride->id}/cancel", [
                'reason' => 'Trip cancelled before driver approval.',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_cancel_trip_can_cancel_a_full_ride(): void
    {
        $ride = $this->insertRide(['status' => 'full']);

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/trips/{$ride->id}/cancel", [
                'reason' => 'Full ride cancelled by staff for operational reasons.',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('rides', ['id' => $ride->id, 'status' => 'cancelled']);
    }

    public function test_cancel_trip_can_cancel_an_awaiting_confirmation_ride(): void
    {
        $ride = $this->insertRide(['status' => 'awaiting_confirmation']);

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/trips/{$ride->id}/cancel", [
                'reason' => 'Awaiting-confirmation ride cancelled by support.',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('rides', ['id' => $ride->id, 'status' => 'cancelled']);
    }

    public function test_cancel_trip_requires_reason(): void
    {
        $ride = $this->insertRide(['status' => 'active']);

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/trips/{$ride->id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['reason']]);
    }

    public function test_cancel_trip_requires_reason_at_least_10_characters(): void
    {
        $ride = $this->insertRide(['status' => 'active']);

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/trips/{$ride->id}/cancel", [
                'reason' => 'Too short',   // 9 chars
            ])
            ->assertStatus(422);
    }

    public function test_cancel_trip_returns_404_for_nonexistent_ride(): void
    {
        $this->withToken($this->agentToken)
            ->postJson('/api/staff/trips/999999/cancel', [
                'reason' => 'Valid cancellation reason for this ride.',
            ])
            ->assertStatus(404);
    }

    public function test_cancel_trip_rejects_already_cancelled_ride(): void
    {
        $ride = $this->insertRide(['status' => 'cancelled']);

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/trips/{$ride->id}/cancel", [
                'reason' => 'Attempting to cancel an already cancelled ride.',
            ])
            ->assertStatus(422);
    }

    public function test_cancel_trip_rejects_finished_ride(): void
    {
        $ride = $this->insertRide(['status' => 'finished']);

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/trips/{$ride->id}/cancel", [
                'reason' => 'Attempting to cancel a finished ride.',
            ])
            ->assertStatus(422);
    }

    public function test_cancel_trip_requires_authentication(): void
    {
        $ride = $this->insertRide();

        $this->postJson("/api/staff/trips/{$ride->id}/cancel", [
            'reason' => 'Valid cancellation reason here.',
        ])->assertStatus(401);
    }

    public function test_cancel_trip_response_includes_cancelled_booking_count(): void
    {
        $ride = $this->insertRide(['status' => 'active']);
        $this->makeBooking($ride, 'confirmed');
        $this->makeBooking($ride, 'confirmed');

        $response = $this->withToken($this->agentToken)
            ->postJson("/api/staff/trips/{$ride->id}/cancel", [
                'reason' => 'Trip cancelled with two active bookings on board.',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['ride_id', 'new_status', 'bookings_cancelled']]);
    }

    public function test_cancel_trip_new_status_field_is_cancelled(): void
    {
        $ride = $this->insertRide(['status' => 'active']);

        $response = $this->withToken($this->agentToken)
            ->postJson("/api/staff/trips/{$ride->id}/cancel", [
                'reason' => 'Trip cancelled — valid reason supplied here.',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('cancelled', $response->json('data.new_status'));
    }

    public function test_cancel_trip_leaves_already_cancelled_booking_untouched(): void
    {
        $ride = $this->insertRide(['status' => 'active']);
        $booking = $this->makeBooking($ride, 'cancelled');

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/trips/{$ride->id}/cancel", [
                'reason' => 'Cancelling an active trip that has a pre-cancelled booking.',
            ])
            ->assertStatus(200);

        // Still cancelled (not double-cancelled or re-opened).
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'cancelled',
        ]);
    }

    // =========================================================================
    // cancelBooking()   POST /api/staff/bookings/{bookingId}/cancel
    // =========================================================================

    public function test_cancel_booking_sets_booking_status_to_cancelled(): void
    {
        $ride = $this->insertRide(['status' => 'active']);
        $booking = $this->makeBooking($ride, 'confirmed');

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/bookings/{$booking->id}/cancel", [
                'reason' => 'Booking cancelled by staff at passenger request.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_cancel_booking_restores_seats_to_the_ride(): void
    {
        $ride = $this->insertRide(['status' => 'active', 'available_seats' => 3]);
        $booking = $this->makeBooking($ride, 'confirmed', seats: 2);

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/bookings/{$booking->id}/cancel", [
                'reason' => 'Staff cancelling booking and restoring seats to ride.',
            ])
            ->assertStatus(200);

        // 3 original seats + 2 restored = 5.
        $this->assertDatabaseHas('rides', [
            'id' => $ride->id,
            'available_seats' => 5,
        ]);
    }

    public function test_cancel_booking_on_full_ride_resets_ride_to_active(): void
    {
        $ride = $this->insertRide(['status' => 'full', 'available_seats' => 0]);
        $booking = $this->makeBooking($ride, 'confirmed', seats: 2);

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/bookings/{$booking->id}/cancel", [
                'reason' => 'Cancelling booking on a full ride to free up seats.',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('rides', [
            'id' => $ride->id,
            'status' => 'active',
        ]);
    }

    public function test_cancel_booking_can_cancel_a_pending_booking(): void
    {
        $ride = $this->insertRide(['status' => 'active']);
        $booking = $this->makeBooking($ride, 'pending');

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/bookings/{$booking->id}/cancel", [
                'reason' => 'Pending booking cancelled before driver approval.',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'cancelled']);
    }

    public function test_cancel_booking_requires_reason(): void
    {
        $ride = $this->insertRide();
        $booking = $this->makeBooking($ride, 'confirmed');

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/bookings/{$booking->id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['reason']]);
    }

    public function test_cancel_booking_requires_reason_at_least_10_characters(): void
    {
        $ride = $this->insertRide();
        $booking = $this->makeBooking($ride, 'confirmed');

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/bookings/{$booking->id}/cancel", [
                'reason' => 'Too short',   // 9 chars
            ])
            ->assertStatus(422);
    }

    public function test_cancel_booking_returns_404_for_nonexistent_booking(): void
    {
        $this->withToken($this->agentToken)
            ->postJson('/api/staff/bookings/999999/cancel', [
                'reason' => 'Valid cancellation reason here.',
            ])
            ->assertStatus(404);
    }

    public function test_cancel_booking_rejects_already_cancelled_booking(): void
    {
        $ride = $this->insertRide();
        $booking = $this->makeBooking($ride, 'cancelled');

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/bookings/{$booking->id}/cancel", [
                'reason' => 'Attempting to cancel an already cancelled booking.',
            ])
            ->assertStatus(422);
    }

    public function test_cancel_booking_rejects_completed_booking(): void
    {
        $ride = $this->insertRide();
        $booking = $this->makeBooking($ride, 'completed');

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/bookings/{$booking->id}/cancel", [
                'reason' => 'Attempting to cancel a completed booking.',
            ])
            ->assertStatus(422);
    }

    public function test_cancel_booking_requires_authentication(): void
    {
        $ride = $this->insertRide();
        $booking = $this->makeBooking($ride, 'confirmed');

        $this->postJson("/api/staff/bookings/{$booking->id}/cancel", [
            'reason' => 'Valid cancellation reason here.',
        ])->assertStatus(401);
    }

    public function test_cancel_booking_response_includes_seats_restored_field(): void
    {
        $ride = $this->insertRide(['status' => 'active']);
        $booking = $this->makeBooking($ride, 'confirmed', seats: 2);

        $response = $this->withToken($this->agentToken)
            ->postJson("/api/staff/bookings/{$booking->id}/cancel", [
                'reason' => 'Booking cancelled — seats will be restored to ride.',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['booking_id', 'new_status', 'seats_restored_to_ride']]);

        $this->assertEquals(2, $response->json('data.seats_restored_to_ride'));
    }

    public function test_cancel_booking_new_status_field_is_cancelled(): void
    {
        $ride = $this->insertRide(['status' => 'active']);
        $booking = $this->makeBooking($ride, 'confirmed');

        $response = $this->withToken($this->agentToken)
            ->postJson("/api/staff/bookings/{$booking->id}/cancel", [
                'reason' => 'Valid cancellation reason supplied to endpoint.',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('cancelled', $response->json('data.new_status'));
    }

    public function test_cancel_booking_booking_id_in_response_matches_cancelled_booking(): void
    {
        $ride = $this->insertRide(['status' => 'active']);
        $booking = $this->makeBooking($ride, 'confirmed');

        $response = $this->withToken($this->agentToken)
            ->postJson("/api/staff/bookings/{$booking->id}/cancel", [
                'reason' => 'Cancellation with booking ID check in response body.',
            ]);

        $response->assertStatus(200);
        $this->assertEquals($booking->id, $response->json('data.booking_id'));
    }

    public function test_cancel_booking_on_awaiting_confirmation_ride_keeps_ride_status(): void
    {
        $ride = $this->insertRide(['status' => 'awaiting_confirmation', 'available_seats' => 2]);
        $booking = $this->makeBooking($ride, 'confirmed', seats: 1);

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/bookings/{$booking->id}/cancel", [
                'reason' => 'Cancelling one booking on an awaiting-confirmation ride.',
            ])
            ->assertStatus(200);

        // Ride should still have the awaiting_confirmation status (not reverted to active).
        $this->assertDatabaseHas('rides', [
            'id' => $ride->id,
            'status' => 'awaiting_confirmation',
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeEmployee(StaffRole $role, string $email, string $username): Employee
    {
        return Employee::create([
            'username' => $username,
            'email' => $email,
            'password' => bcrypt('password123'),
            'first_name' => 'Staff',
            'last_name' => 'Member',
            'role' => $role->value,
            'is_active' => true,
            'token_version' => 0,
        ]);
    }

    private function insertRide(array $overrides = []): Ride
    {
        $status = $overrides['status'] ?? 'active';
        $seats = $overrides['available_seats'] ?? 4;
        $price = $overrides['price_per_seat'] ?? 50000;
        $departure = isset($overrides['departure_time'])
            ? (is_string($overrides['departure_time'])
                ? $overrides['departure_time']
                : $overrides['departure_time']->format('Y-m-d H:i:s'))
            : now()->addHours(3)->format('Y-m-d H:i:s');

        DB::statement("
            INSERT INTO rides (
                driver_id, pickup_address, destination_address,
                pickup_location, destination_location,
                departure_time, available_seats, price_per_seat,
                payment_method, booking_type, status,
                distance, duration, communication_number,
                created_at, updated_at
            ) VALUES (
                ?, 'Damascus', 'Aleppo',
                ST_GeomFromText('POINT(33.5138 36.2765)', 4326),
                ST_GeomFromText('POINT(36.2021 37.1343)', 4326),
                ?, ?, ?, 'cash', 'direct', ?,
                320.5, 240, ?,
                NOW(), NOW()
            )
        ", [
            $this->driver->id,
            $departure,
            $seats,
            $price,
            $status,
            $this->driverPhone,
        ]);

        return Ride::latest('id')->first();
    }

    private function makeBooking(
        Ride $ride,
        string $status,
        ?User $passenger = null,
        int $seats = 1,
    ): Booking {
        return Booking::create([
            'user_id' => ($passenger ?? $this->passenger)->id,
            'ride_id' => $ride->id,
            'seats' => $seats,
            'status' => $status,
            'communication_number' => $this->passengerPhone,
        ]);
    }

    private function seedAdminWallets(): void
    {
        foreach (['system_admin', 'sycash'] as $type) {
            $cfg = config("admin.{$type}");
            $user = User::firstOrCreate(
                ['email' => $cfg['email']],
                [
                    'first_name' => $type,
                    'last_name' => 'Admin',
                    'password' => bcrypt($cfg['password']),
                    'gender' => 'M',
                    'address' => 'Damascus',
                    'status' => true,
                ]
            );
            if (! Wallet::where('phone_number', $cfg['phone'])->exists()) {
                $w = Wallet::create([
                    'user_id' => $user->id,
                    'phone_number' => $cfg['phone'],
                    'balance' => 10_000_000,
                ]);
                $user->update(['wallet_id' => $w->id]);
            } else {
                Wallet::where('phone_number', $cfg['phone'])
                    ->update(['balance' => 10_000_000]);
            }
        }
    }

    private function getStaffToken(string $identifier, string $password): string
    {
        return $this->postJson('/api/staff/login', [
            'identifier' => $identifier,
            'password' => $password,
        ])->json('tokens.access_token');
    }
}
