<?php

namespace Tests\Feature\Snapshots;

/**
 * Batch H (stage 0.5, step 7) - the REFUSAL doors of the money and ride surface.
 *
 * Before this batch the corpus pinned 153 responses and NOT ONE of them was a 403, 404, 409 or 422
 * on a money or ride route: the only error statuses anywhere were 400/401 from auth and OTP. That is
 * the shape of a corpus that documents the happy path and leaves the boundary untested - and the
 * boundary is where a client actually needs to know what it will receive: which code, which message
 * key, which `errors` shape.
 *
 * Every case here is a POST/PATCH/DELETE on a wallet, booking or ride route, so each one is also in
 * `MONEY_AND_RIDE_MUTATION_CASES` and therefore pins the ROWS as well as the body: a refusal that
 * half-wrote a booking, moved a balance or emptied an escrow before validating would show up in the
 * state snapshot even though the response looks innocent.
 *
 * The refusals are produced by the app as it is - the bodies are whatever it answers. Nothing here
 * was tuned to a status: the statuses were recorded from a generation run (see the audit section),
 * which is the same rule the rest of the corpus follows.
 */
final class ErrorPathSnapshotTest extends ApiSnapshotTestCase
{
    public static function cases(): array
    {
        $departureInThePast = self::snapshotNow()->subDay()->format('Y-m-d H:i:s');

        return [
            'wallet/request-withdraw-more-than-balance' => [
                'route' => 'POST /api/wallet/request-withdraw',
                'method' => 'POST',
                'uri' => '/api/wallet/request-withdraw',
                'actor' => 'passenger',
                'body' => [
                    'amount' => 5_000_000,
                    'notes' => 'More than the passenger holds',
                ],
                'expect' => 422,
            ],
            'wallet/request-charge-zero-amount' => [
                'route' => 'POST /api/wallet/request-charge',
                'method' => 'POST',
                'uri' => '/api/wallet/request-charge',
                'actor' => 'passenger',
                'body' => [
                    'amount' => 0,
                    'notes' => 'A top-up of nothing',
                ],
                'expect' => 422,
            ],
            'wallet/requests-store-unknown-type' => [
                'route' => 'POST /api/wallet/requests',
                'method' => 'POST',
                'uri' => '/api/wallet/requests',
                'actor' => 'passenger',
                'body' => [
                    'type' => 'refund',
                    'amount' => 1_000,
                    'notes' => 'Not a charge and not a withdraw',
                ],
                'expect' => 422,
            ],
            'wallet/create-direct-already-has-a-wallet' => [
                'route' => 'POST /api/wallet/create-direct',
                'method' => 'POST',
                'uri' => '/api/wallet/create-direct',
                'actor' => 'passenger',
                'body' => [
                    'phone_number' => '0900000009',
                ],
                // 409, not 422: "You already have a wallet." conflicts with existing state. The
                // status is the app's own; the case documents it rather than preferring one.
                'expect' => 409,
            ],
            'wallet/requests-destroy-not-the-owner' => [
                'route' => 'DELETE /api/wallet/requests/{id}',
                'method' => 'DELETE',
                'uri' => '/api/wallet/requests/{request}',
                'actor' => 'other',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->createWalletRequest('charge', 3_000),
                // 404, not 403: the route scopes the lookup to the actor, so another account's
                // request is not found rather than forbidden - which also avoids confirming that
                // the id exists at all.
                'expect' => 404,
            ],
            'rides/book-without-the-balance' => [
                'route' => 'POST /api/rides/{rideId}/book',
                'method' => 'POST',
                'uri' => '/api/rides/{ride}/book',
                'actor' => 'other',
                'prepare' => static function (ApiSnapshotTestCase $test): void {
                    // The booking itself is valid; the wallet behind it cannot pay the 50,000 seat.
                    $test->otherWallet->update(['balance' => 100]);
                },
                'body' => [
                    'seats' => 1,
                    'communication_number' => '0900000003',
                    'idempotency_key' => '22222222-2222-4222-8222-222222222222',
                ],
                'expect' => 422,
            ],
            'rides/book-more-seats-than-the-ride-has' => [
                'route' => 'POST /api/rides/{rideId}/book',
                'method' => 'POST',
                'uri' => '/api/rides/{ride}/book',
                'actor' => 'other',
                'body' => [
                    'seats' => 5,
                    'communication_number' => '0900000003',
                    'idempotency_key' => '33333333-3333-4333-8333-333333333333',
                ],
                'expect' => 422,
            ],
            'rides/book-the-drivers-own-ride' => [
                'route' => 'POST /api/rides/{rideId}/book',
                'method' => 'POST',
                'uri' => '/api/rides/{ride}/book',
                'actor' => 'driver',
                'body' => [
                    'seats' => 1,
                    'communication_number' => '0900000001',
                    'idempotency_key' => '44444444-4444-4444-8444-444444444444',
                ],
                'expect' => 422,
            ],
            'rides/cancel-someone-elses-trip' => [
                'route' => 'PATCH /api/rides/{rideId}/cancel',
                'method' => 'PATCH',
                'uri' => '/api/rides/{ride}/cancel',
                'actor' => 'other',
                'with_booking' => true,
                // 422 "Only the ride creator can cancel it", not 403: the creator guard runs before
                // any policy layer and reports the refusal as a validation error.
                'expect' => 422,
            ],
            'rides/create-departure-in-the-past' => [
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
                    'departure_time' => $departureInThePast,
                    'available_seats' => 3,
                    'price_per_seat' => 25_000,
                    'payment_method' => 'e-pay',
                    'booking_type' => 'direct',
                    'communication_number' => '0911000000',
                    'vehicle_type' => 'car',
                    'notes' => 'A trip that already left',
                ],
                'expect' => 422,
            ],
            'bookings/accept-someone-elses-request' => [
                'route' => 'POST /api/bookings/{bookingId}/accept',
                'method' => 'POST',
                'uri' => '/api/bookings/{booking}/accept',
                'actor' => 'passenger',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->pendingRequestBooking(),
                // 422 with a generic "The request could not be completed. Please try again." rather
                // than 403: a masked authorization refusal, parked for the owner (no app change in
                // this batch) because a client cannot tell it from a validation failure.
                'expect' => 422,
            ],
            'bookings/cancel-someone-elses-booking' => [
                'route' => 'POST /api/bookings/{bookingId}/cancel',
                'method' => 'POST',
                'uri' => '/api/bookings/{booking}/cancel',
                'actor' => 'other',
                'with_booking' => true,
                // Same masked refusal as the accept case above: 422, not 403.
                'expect' => 422,
            ],
            'bookings/cancel-more-seats-than-booked' => [
                'route' => 'POST /api/bookings/{bookingId}/cancel-seats',
                'method' => 'POST',
                'uri' => '/api/bookings/{booking}/cancel-seats',
                'actor' => 'passenger',
                'with_booking' => true,
                'booking_seats' => 2,
                'body' => ['seats_to_cancel' => 5],
                'expect' => 422,
            ],
            'admin/wallet-charge-a-negative-amount' => [
                'route' => 'POST /api/admin/wallet/charge',
                'method' => 'POST',
                'uri' => '/api/admin/wallet/charge',
                'actor' => 'admin',
                'body' => [
                    'phone_number' => '0900000002',
                    'amount' => -5_000,
                ],
                'expect' => 422,
            ],
            'admin/passenger-charge-a-negative-amount' => [
                'route' => 'POST /api/admin/passengers/{userId}/charge-wallet',
                'method' => 'POST',
                'uri' => '/api/admin/passengers/{passenger}/charge-wallet',
                'actor' => 'admin',
                'body' => [
                    'amount' => -100,
                    'admin_notes' => 'Taking money back the wrong way',
                ],
                'expect' => 422,
            ],
            'staff/bookings-cancel-a-booking-that-does-not-exist' => [
                'route' => 'POST /api/staff/bookings/{bookingId}/cancel',
                'method' => 'POST',
                'uri' => '/api/staff/bookings/999999/cancel',
                'actor' => 'staff_admin',
                'body' => ['reason' => 'No such booking.'],
                'expect' => 404,
            ],
        ];
    }
}
