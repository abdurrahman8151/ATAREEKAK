<?php

namespace App\Modules\Console\Services;

use App\Modules\Identity\Contracts\UserDirectory;
use App\Modules\Ride\Contracts\StaffBookingList;
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
    public function __construct(
        private readonly UserDirectory $users,
    ) {}

    /**
     * @param  array{status?: string, user_id?: int, ride_id?: int, per_page?: int, page?: int}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        // Stage 2 C2: the query over `bookings` (and its ride eager load) is Ride's; the rows come back here
        // and the formatter below shapes them.
        return app(StaffBookingList::class)->paginateStaffBookings($filters);
    }

    /**
     * Stage 2 C3: shape a whole page in one pass - the passengers' and drivers' names/emails arrive from
     * Identity's directory in ONE query for the page (the two eager loads this replaces cost two).
     *
     * @return array<int, array<string, mixed>>
     */
    public function formatPage(LengthAwarePaginator $paginator): array
    {
        $bookings = $paginator->getCollection();

        $userIds = $bookings
            ->pluck('user_id')
            ->merge($bookings->map(fn ($booking) => $booking->ride?->driver_id))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $summaries = $this->users->summariesFor($userIds);

        return $bookings
            ->map(fn ($booking) => $this->toRow($booking, $summaries))
            ->values()
            ->all();
    }

    /**
     * Map one booking into the public row shape. Kept identical to the original controller output.
     *
     * `$summaries` is Identity's map keyed by user id; the values are read by property - this module never
     * names the DTO class.
     *
     * @param  array<int, object>  $summaries
     * @return array<string, mixed>
     */
    public function toRow($booking, array $summaries = []): array
    {
        $passenger = $booking->user_id ? ($summaries[(int) $booking->user_id] ?? null) : null;
        $driver = $booking->ride?->driver_id ? ($summaries[(int) $booking->ride->driver_id] ?? null) : null;

        return [
            'id' => $booking->id,
            'status' => $booking->status,
            'seats' => $booking->seats,
            'communication_number' => $booking->communication_number,
            'passenger' => [
                'id' => $passenger?->id,
                'name' => $passenger !== null ? $passenger->fullName() : '',
                'email' => $passenger?->email,
            ],
            'ride' => [
                'id' => $booking->ride?->id,
                'pickup_address' => $booking->ride?->pickup_address,
                'destination_address' => $booking->ride?->destination_address,
                'departure_time' => $booking->ride?->departure_time?->toIso8601String(),
                'price_per_seat' => $booking->ride?->price_per_seat,
                'ride_status' => $booking->ride?->status,
                'driver' => [
                    'id' => $driver?->id,
                    'name' => $driver !== null ? $driver->fullName() : '',
                ],
            ],
            'total_price' => $booking->seats * ($booking->ride?->price_per_seat ?? 0),
            'booked_at' => $booking->created_at->toIso8601String(),
            'completed_at' => $booking->completed_at?->toIso8601String(),
        ];
    }
}
