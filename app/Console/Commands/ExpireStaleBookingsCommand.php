<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Decision 2 (owner, 2026-10-02, Option B): "expire unconfirmed bookings, do NOT auto-confirm".
 *
 * The owner's alternative was rejected deliberately: auto-confirming a request the driver never
 * accepted would book a passenger into a car whose driver did not agree to carry them, and would
 * CHARGE them for it. Expiry is the honest outcome - the request lapses and nothing happens.
 *
 * WHAT THIS COMMAND DELIBERATELY DOES NOT DO (all three verified in code before writing this):
 *   - it moves NO money. A PENDING booking is charged only at `acceptBooking`, so an expired one
 *     has `amount_paid = 0` and never reached SyCash. There is no escrow to release.
 *   - it restores NO seats. `deductSeats` runs only on accept, so the seats were never taken.
 *   - it never touches a CONFIRMED booking. Only `pending` is in scope; a booking the driver DID
 *     accept is a real booking and must not be silently undone by a cleanup job.
 *
 * EXPIRY RULE: a request lapses once the ride's departure time has passed. That is the only moment
 * the request becomes meaningless - before it, the driver may still reasonably accept.
 *
 * IDEMPOTENT: re-running finds nothing, because expired rows are no longer `pending`. Safe to run on
 * every scheduler tick.
 */
class ExpireStaleBookingsCommand extends Command
{
    protected $signature = 'bookings:expire-stale';

    protected $description = 'Expire booking requests the driver never answered (past ride departure)';

    public function handle(NotificationService $notifications): int
    {
        $this->info('Expiring unaccepted booking requests...');

        // Booked IDs are collected before the write so a notification failure cannot leave a
        // booking expired with nobody told. Each row is expired in its own transaction.
        $stale = Booking::query()
            ->where('status', BookingStatus::PENDING->value)
            ->whereHas('ride', fn ($q) => $q->where('departure_time', '<', now()))
            ->with(['ride', 'user'])
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No stale booking requests.');

            return self::SUCCESS;
        }

        $expired = 0;
        $notified = 0;

        foreach ($stale as $booking) {
            DB::transaction(function () use ($booking, $notifications, &$expired, &$notified) {
                // Re-check under the transaction: the driver may have accepted between the query
                // above and this write. Without it a cleanup job could expire a booking that is
                // already confirmed and, for e-pay, already charged.
                //
                // `with('ride')` is load-bearing under the armed lazy-loading guard: `lockForUpdate()`
                // re-reads the row, so a re-fetch without the relation would raise
                // LazyLoadingViolationException the moment `$fresh->ride` is touched.
                $fresh = Booking::lockForUpdate()->with('ride')->find($booking->id);

                if (! $fresh || $fresh->status !== BookingStatus::PENDING->value) {
                    return;
                }

                $ride = $fresh->ride;

                if (! $ride || now()->lt($ride->departure_time)) {
                    return;
                }

                $fresh->update(['status' => BookingStatus::EXPIRED->value]);
                $expired++;

                Log::info('Booking request expired (driver never responded)', [
                    'booking_id' => $fresh->id,
                    'ride_id' => $ride->id,
                    'passenger_id' => $fresh->user_id,
                    'departure_time' => (string) $ride->departure_time,
                ]);

                // The passenger asked for a seat and never got an answer. Telling them is the whole
                // point of the expiry; a silent status change is the bug decision 2 exists to stop.
                try {
                    $notifications->createNotification(
                        $fresh->user,
                        'booking_expired',
                        'انتهت صلاحية طلب الحجز',
                        'لم يرد السائق على طلب الحجز قبل موعد الرحلة، لذلك تم إلغاء الطلب تلقائياً. '.
                        'لم يتم خصم أي مبلغ من محفظتك.',
                        ['booking_id' => $fresh->id, 'ride_id' => $ride->id],
                        'normal',
                        'ride'
                    );
                    $notified++;
                } catch (\Throwable $e) {
                    // The booking is expired either way; a failed notification must not roll that
                    // back, or the row would be retried forever and stay pending.
                    Log::warning('Could not notify passenger of expired booking', [
                        'booking_id' => $fresh->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        }

        $this->info(sprintf(
            'Expired %d booking request(s); %d passenger(s) notified.',
            $expired,
            $notified
        ));

        return self::SUCCESS;
    }
}
