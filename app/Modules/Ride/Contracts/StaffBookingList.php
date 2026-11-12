<?php

namespace App\Modules\Ride\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The staff bookings list - the filtered, paginated query behind the back office's bookings screen.
 *
 * WHY A PORT (Stage 2 C2). `bookings` is Ride's table, and Console built this query itself. The port moves the
 * QUERY, not the JSON: it returns the same Eloquent paginator, so the caller keeps mapping each row into the
 * public shape exactly as before and the frozen staff-bookings response is untouched. That is the sec-286
 * precedent (the admin wallet-transaction page), and it is the honest shape here because the rows the
 * paginator carries ARE what the screen serialises.
 *
 * The filter semantics stay with the caller's contract: `status` = 'all' means no status filter, `user_id` and
 * `ride_id` narrow only when present, `per_page`/`page` default to 15/1, and the order is `created_at` DESC.
 */
interface StaffBookingList
{
    /**
     * @param  array{status?: string, user_id?: int, ride_id?: int, per_page?: int, page?: int}  $filters
     */
    public function paginateStaffBookings(array $filters): LengthAwarePaginator;
}
