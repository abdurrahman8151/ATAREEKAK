<?php

namespace Tests\Feature\Snapshots;

/**
 * Batch F - the booking decision doors.
 *
 * The request/accept/reject pair, the passenger's own cancellation (whole booking and part of its
 * seats), the passenger's completion confirmation, and the driver's no-show report. Accept and
 * reject need a booking that is still waiting for the driver, so those cases build a second
 * `request`-type ride instead of the standard `direct` one.
 */
final class BookingsSnapshotTest extends ApiSnapshotTestCase
{
    public static function cases(): array
    {
        return [
            'bookings/accept' => [
                'route' => 'POST /api/bookings/{bookingId}/accept',
                'method' => 'POST',
                'uri' => '/api/bookings/{booking}/accept',
                'actor' => 'driver',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->pendingRequestBooking(),
                'expect' => 200,
            ],
            'bookings/reject' => [
                'route' => 'POST /api/bookings/{bookingId}/reject',
                'method' => 'POST',
                'uri' => '/api/bookings/{booking}/reject',
                'actor' => 'driver',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->pendingRequestBooking(),
                'expect' => 200,
            ],
            'bookings/cancel' => [
                'route' => 'POST /api/bookings/{bookingId}/cancel',
                'method' => 'POST',
                'uri' => '/api/bookings/{booking}/cancel',
                'actor' => 'passenger',
                'with_booking' => true,
                'expect' => 200,
            ],
            'bookings/cancel-seats' => [
                'route' => 'POST /api/bookings/{bookingId}/cancel-seats',
                'method' => 'POST',
                'uri' => '/api/bookings/{booking}/cancel-seats',
                'actor' => 'passenger',
                'with_booking' => true,
                'booking_seats' => 2,
                'body' => ['seats_to_cancel' => 1],
                'expect' => 200,
            ],
            'bookings/passenger-confirm' => [
                'route' => 'POST /api/bookings/{bookingId}/passenger-confirm',
                'method' => 'POST',
                'uri' => '/api/bookings/{booking}/passenger-confirm',
                'actor' => 'passenger',
                'with_booking' => true,
                'prepare' => static function (ApiSnapshotTestCase $test): void {
                    // Confirmation opens after the departure time; move the ride into the past so
                    // the case exercises the settlement path rather than the refusal.
                    $test->ride->update(['departure_time' => now()->subHour()]);
                },
                'expect' => 200,
            ],
            'bookings/passenger-no-show' => [
                'route' => 'POST /api/bookings/{bookingId}/passenger-no-show',
                'method' => 'POST',
                'uri' => '/api/bookings/{booking}/passenger-no-show',
                'actor' => 'driver',
                'with_booking' => true,
                'prepare' => static function (ApiSnapshotTestCase $test): void {
                    // The driver may only report a no-show once the departure time has passed.
                    $test->ride->update(['departure_time' => now()->subHours(2)]);
                },
                'expect' => 200,
            ],
        ];
    }
}
