<?php

namespace App\Modules\Console\Services;

use App\Models\Booking;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * L6 (modular-monolith slice 5): the read side of the staff bookings list.
 *
 * `StaffOperationsController::bookings()` used to build the query, the filters, the pagination and the
 * row shape inside one 90-line method. This service owns that read logic. The controller keeps the
 * HTTP concerns: validation, the response envelope and error handling. The row shape is unchanged,
 * so the public response does not change.
 */
final class StaffBookingQueryService
{
    /**
     * @param  array{status?: string, user_id?: int, ride_id?: int, per_page?: int, page?: int}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Booking::with([
            'user:id,first_name,last_name,email',
            'ride:id,pickup_address,destination_address,departure_time,price_per_seat,status,driver_id',
            'ride.driver:id,first_name,last_name',
        ]);

        $status = $filters['status'] ?? 'all';
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (isset($filters['ride_id'])) {
            $query->where('ride_id', (int) $filters['ride_id']);
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate(
                (int) ($filters['per_page'] ?? 15),
                ['*'],
                'page',
                (int) ($filters['page'] ?? 1)
            );
    }

    /**
     * Map one booking into the public row shape. Kept identical to the original controller output.
     *
     * @return array<string, mixed>
     */
    public function toRow(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'status' => $booking->status,
            'seats' => $booking->seats,
            'communication_number' => $booking->communication_number,
            'passenger' => [
                'id' => $booking->user?->id,
                'name' => trim(($booking->user?->first_name ?? '').' '.($booking->user?->last_name ?? '')),
                'email' => $booking->user?->email,
            ],
            'ride' => [
                'id' => $booking->ride?->id,
                'pickup_address' => $booking->ride?->pickup_address,
                'destination_address' => $booking->ride?->destination_address,
                'departure_time' => $booking->ride?->departure_time?->toIso8601String(),
                'price_per_seat' => $booking->ride?->price_per_seat,
                'ride_status' => $booking->ride?->status,
                'driver' => [
                    'id' => $booking->ride?->driver?->id,
                    'name' => trim(
                        ($booking->ride?->driver?->first_name ?? '').' '.
                        ($booking->ride?->driver?->last_name ?? '')
                    ),
                ],
            ],
            'total_price' => $booking->seats * ($booking->ride?->price_per_seat ?? 0),
            'booked_at' => $booking->created_at->toIso8601String(),
            'completed_at' => $booking->completed_at?->toIso8601String(),
        ];
    }
}
