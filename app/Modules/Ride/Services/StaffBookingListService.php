<?php

namespace App\Modules\Ride\Services;

use App\Modules\Ride\Contracts\StaffBookingList;
use App\Modules\Ride\Models\Booking;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The staff bookings query behind {@see StaffBookingList} - Console's own statement, verbatim: the same three
 * eager loads with the same column lists, the same three optional filters, the same `created_at` DESC ordering
 * and the same pagination defaults.
 */
final class StaffBookingListService implements StaffBookingList
{
    public function paginateStaffBookings(array $filters): LengthAwarePaginator
    {
        $query = Booking::with([
            // Stage 2 C3: the passenger's and the driver's rows are no longer eager-loaded - the console
            // formatter batches both name/email reads from Identity's directory, ONE query for the page.
            'ride:id,pickup_address,destination_address,departure_time,price_per_seat,status,driver_id',
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
}
