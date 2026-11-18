<?php

namespace App\Modules\Ride\Services;

use App\Domain\ValueObjects\Money;
use App\Enums\BookingStatus;
use App\Modules\Ride\Contracts\RideMaintenance;
use App\Modules\Ride\Models\Booking;
use App\Modules\Ride\Models\Ride;
use Illuminate\Support\Facades\DB;

/**
 * Stage 2 C2: Ride's maintenance surface, moved out of the three artisan commands that used to hold it.
 *
 * The bodies are the commands' own logic, verbatim in behaviour: the same filters, the same row lock and
 * re-check, the same snapshot arithmetic (`Money::from()->divide()`), the same "e-pay without an escrow row is
 * skipped, never guessed" rule, the same relationship between `seats` and `unit_price`.
 */
final class RideMaintenanceService implements RideMaintenance
{
    public function stalePendingBookingIds(): array
    {
        return Booking::query()
            ->where('status', BookingStatus::PENDING->value)
            ->whereHas('ride', fn ($q) => $q->where('departure_time', '<', now()))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function expireIfStillStale(int $bookingId): ?array
    {
        return DB::transaction(function () use ($bookingId) {
            // `with('ride')` is load-bearing: lockForUpdate() re-reads the row, and a re-fetch without the
            // relation would raise LazyLoadingViolationException the moment the ride is touched.
            $fresh = Booking::lockForUpdate()->with('ride')->find($bookingId);

            if (! $fresh || $fresh->status !== BookingStatus::PENDING->value) {
                return null;
            }

            $ride = $fresh->ride;

            if (! $ride || now()->lt($ride->departure_time)) {
                return null;
            }

            $fresh->update(['status' => BookingStatus::EXPIRED->value]);

            return [
                'booking_id' => (int) $fresh->id,
                'ride_id' => $ride->id === null ? null : (int) $ride->id,
                'passenger_id' => $fresh->user_id === null ? null : (int) $fresh->user_id,
                'departure_time' => $ride->departure_time === null ? null : (string) $ride->departure_time,
            ];
        });
    }

    public function unfilledSnapshotBookingIds(): array
    {
        return Booking::query()
            ->where('amount_paid', 0)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function backfillSnapshot(int $bookingId, ?float $escrowedAmount, bool $dry): string
    {
        $booking = Booking::with('ride')->find($bookingId);

        if (! $booking) {
            return 'skipped';
        }

        $ride = $booking->ride;

        if ($ride && $ride->payment_method === 'e-pay') {
            return $this->fillEPaySnapshot($booking, $escrowedAmount, $dry);
        }

        return $this->markCashMethodSnapshot($booking, $ride, $dry);
    }

    public function bookingContexts(array $bookingIds): array
    {
        if ($bookingIds === []) {
            return [];
        }

        return Booking::query()
            ->with(['ride'])
            ->whereIn('id', $bookingIds)
            ->get()
            ->mapWithKeys(fn (Booking $booking) => [$booking->id => [
                'ride_id' => $booking->ride?->id === null ? null : (int) $booking->ride->id,
                'seats' => $booking->seats === null ? null : (int) $booking->seats,
                'departure_time' => $booking->ride?->departure_time === null ? null : (string) $booking->ride->departure_time,
                'user_id' => $booking->user_id === null ? null : (int) $booking->user_id,
            ]])
            ->all();
    }

    /**
     * @return 'e-pay'|'skipped'
     */
    private function fillEPaySnapshot(Booking $booking, ?float $paid, bool $dry): string
    {
        if ($paid === null || $paid <= 0) {
            // An e-pay booking with no escrow ledger row: money was never proven to have moved here. Do not
            // guess. Leave it for manual review.
            return 'skipped';
        }

        // `R2 sec 117`: dividing money is a money operation. `divide()` rounds once, in minor units; the
        // `$booking->seats > 0` guard is what makes its `divisor must be positive` precondition hold.
        $unitPrice = $booking->seats > 0
            ? Money::from($paid)->divide((float) $booking->seats)->amount()
            : $paid;

        if (! $dry) {
            $booking->forceFill([
                'unit_price' => $unitPrice,
                'amount_paid' => $paid,
                'payment_method' => 'e-pay',
                // RV-02 L2: what this booking has in SyCash RIGHT NOW, taken from the escrow ledger row the
                // command already located. Writing 0 here would strip a live, unreleased escrow of the only
                // record that makes its settlement safe to run. Clamped at 0 because this path reads only money
                // IN, never money already paid back out.
                'escrow_held' => max(0.0, $paid),
            ])->save();
        }

        return 'e-pay';
    }

    /**
     * Cash (or ride-less): no escrow amount is recoverable; mark the method snapshot only. `escrow_held` stays
     * 0; the amount is settled offline.
     *
     * @return 'cash'
     */
    private function markCashMethodSnapshot(Booking $booking, ?Ride $ride, bool $dry): string
    {
        if (! $dry && $booking->payment_method === null) {
            $booking->forceFill(['payment_method' => $ride?->payment_method ?? 'cash'])
                ->save();
        }

        return 'cash';
    }
}
