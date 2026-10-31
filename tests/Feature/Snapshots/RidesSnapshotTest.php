<?php

namespace Tests\Feature\Snapshots;

/**
 * Batch B - the ride lifecycle: create, search, read, book, cancel, report.
 *
 * The largest public surface in the product, and the one that carries the money and the seat
 * arithmetic. Each case pins the full response body: a seat count that drifts, a fare field that
 * changes shape, or a cancellation that stops refunding shows up as a diff here.
 *
 * Geocoding and routing are faked in ApiSnapshotTestCase::fakeThirdPartyServices(), so the search
 * and route-options cases are deterministic and cannot reach the network.
 */
final class RidesSnapshotTest extends ApiSnapshotTestCase
{
    public static function cases(): array
    {
        $departure = now()->addHours(4)->format('Y-m-d H:i:s');

        return [
            'rides/index' => [
                'route' => 'GET /api/rides',
                'method' => 'GET',
                'uri' => '/api/rides',
                'actor' => 'driver',
                'expect' => 200,
            ],
            'rides/create' => [
                'route' => 'POST /api/rides',
                'method' => 'POST',
                'uri' => '/api/rides',
                'actor' => 'driver',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->seedDriverDocuments(),
                'body' => [
                    'pickup_address' => 'Damascus',
                    'destination_address' => 'Aleppo',
                    'pickup_lat' => 33.5138,
                    'pickup_lng' => 36.2765,
                    'destination_lat' => 36.2021,
                    'destination_lng' => 37.1343,
                    'departure_time' => $departure,
                    'available_seats' => 3,
                    'price_per_seat' => 25_000,
                    'payment_method' => 'e-pay',
                    'booking_type' => 'direct',
                    'communication_number' => '0911000000',
                    'vehicle_type' => 'car',
                    'notes' => 'Snapshot ride',
                ],
                'expect' => 201,
            ],
            'rides/create-with-route' => [
                'route' => 'POST /api/rides/create-with-route',
                'method' => 'POST',
                'uri' => '/api/rides/create-with-route',
                'actor' => 'driver',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->seedDriverDocuments(),
                'body' => [
                    'pickup_address' => 'Damascus',
                    'destination_address' => 'Aleppo',
                    'pickup_lat' => 33.5138,
                    'pickup_lng' => 36.2765,
                    'destination_lat' => 36.2021,
                    'destination_lng' => 37.1343,
                    'departure_time' => $departure,
                    'available_seats' => 3,
                    'price_per_seat' => 25_000,
                    'payment_method' => 'e-pay',
                    'booking_type' => 'direct',
                    'communication_number' => '0911000000',
                    'vehicle_type' => 'car',
                    'distance' => 320_500,
                    'duration' => 14_400,
                    'route_geometry' => [
                        'type' => 'LineString',
                        'coordinates' => [[36.2765, 33.5138], [37.1343, 36.2021]],
                    ],
                ],
                'expect' => 201,
            ],
            'rides/route-options' => [
                'route' => 'POST /api/rides/route-options',
                'method' => 'POST',
                'uri' => '/api/rides/route-options',
                'actor' => 'driver',
                'body' => [
                    'pickup_lat' => 33.5138,
                    'pickup_lng' => 36.2765,
                    'destination_lat' => 36.2021,
                    'destination_lng' => 37.1343,
                ],
                'expect' => 200,
            ],
            'rides/search-get' => [
                'route' => 'GET /api/rides/search',
                'method' => 'GET',
                'uri' => '/api/rides/search?source_address=Damascus&destination_address=Aleppo&departure_date='
                    .now()->addDay()->format('Y-m-d').'&seats_required=1',
                'actor' => 'passenger',
                'expect' => 200,
            ],
            'rides/search-post' => [
                'route' => 'POST /api/rides/search',
                'method' => 'POST',
                'uri' => '/api/rides/search',
                'actor' => 'passenger',
                'body' => [
                    'source_address' => 'Damascus',
                    'destination_address' => 'Aleppo',
                    'departure_date' => now()->addDay()->format('Y-m-d'),
                    'seats_required' => 1,
                ],
                'expect' => 200,
            ],
            'rides/show' => [
                'route' => 'GET /api/rides/{rideId}',
                'method' => 'GET',
                'uri' => '/api/rides/{ride}',
                'actor' => 'passenger',
                'with_booking' => true,
                'expect' => 200,
            ],
            'rides/book' => [
                'route' => 'POST /api/rides/{rideId}/book',
                'method' => 'POST',
                'uri' => '/api/rides/{ride}/book',
                'actor' => 'other',
                'body' => [
                    'seats' => 1,
                    'communication_number' => '0900000003',
                    'idempotency_key' => '11111111-1111-4111-8111-111111111111',
                ],
                'expect' => 201,
            ],
            'rides/cancel' => [
                'route' => 'PATCH /api/rides/{rideId}/cancel',
                'method' => 'PATCH',
                'uri' => '/api/rides/{ride}/cancel',
                'actor' => 'driver',
                'with_booking' => true,
                'expect' => 200,
            ],
            'rides/driver-no-show' => [
                'route' => 'POST /api/rides/{rideId}/driver-no-show',
                'method' => 'POST',
                'uri' => '/api/rides/{ride}/driver-no-show',
                'actor' => 'passenger',
                'with_booking' => true,
                'prepare' => static function (ApiSnapshotTestCase $test): void {
                    // Reporting a driver unlocks one hour after departure, so move the standard
                    // ride into the past instead of waiting for the clock.
                    $test->ride->update(['departure_time' => now()->subHours(2)]);
                },
                'expect' => 200,
            ],
            'rides/passengers' => [
                'route' => 'GET /api/rides/{rideId}/passengers',
                'method' => 'GET',
                'uri' => '/api/rides/{ride}/passengers',
                'actor' => 'driver',
                'with_booking' => true,
                'expect' => 200,
            ],
            'rides/autocomplete' => [
                'route' => 'GET /api/autocomplete',
                'method' => 'GET',
                'uri' => '/api/autocomplete?text=Damascus',
                'actor' => 'passenger',
                'expect' => 200,
            ],
            'bookings/index' => [
                'route' => 'GET /api/bookings',
                'method' => 'GET',
                'uri' => '/api/bookings',
                'actor' => 'passenger',
                'with_booking' => true,
                'expect' => 200,
            ],
        ];
    }
}
