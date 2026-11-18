<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Communication\Contracts\NotificationPort;
use App\Modules\Ride\Contracts\RideMaintenance;
use App\Support\Logging\SafeExceptionLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Expire booking requests the driver never answered (past ride departure).
 *
 * Stage 2 C2: the row access moved into `RideMaintenance` (list, locked re-check, write). This command keeps the
 * orchestration, the log line, the passenger notification and the summary - and the recipient is one lookup of
 * the KERNEL principal, which an entrypoint may name; a module's model is what it may not.
 */
class ExpireStaleBookingsCommand extends Command
{
    protected $signature = 'bookings:expire-stale';

    protected $description = 'Expire booking requests the driver never answered (past ride departure)';

    public function handle(RideMaintenance $bookings, NotificationPort $notifications): int
    {
        $this->info('Expiring unaccepted booking requests...');

        $stale = $bookings->stalePendingBookingIds();

        if ($stale === []) {
            $this->info('No stale booking requests.');

            return self::SUCCESS;
        }

        $expired = 0;
        $notified = 0;

        foreach ($stale as $bookingId) {
            // Each row is expired in its own transaction, inside the owner: the lock, the re-check and the write
            // are one unit there, so a driver who accepted between the list query and this call is left alone.
            $row = $bookings->expireIfStillStale($bookingId);

            if ($row === null) {
                continue;
            }

            $expired++;

            Log::info('Booking request expired (driver never responded)', [
                'booking_id' => $row['booking_id'],
                'ride_id' => $row['ride_id'],
                'passenger_id' => $row['passenger_id'],
                'departure_time' => $row['departure_time'],
            ]);

            if ($this->notifyPassenger($notifications, $row)) {
                $notified++;
            }
        }

        $this->reportSummary($expired, $notified);

        return self::SUCCESS;
    }

    /**
     * The passenger asked for a seat and never got an answer. Telling them is the whole point of the expiry; a
     * silent status change is the bug decision 2 exists to stop.
     *
     * A failed notification must not roll the expiry back, or the row would be retried forever and stay pending.
     *
     * @param  array{booking_id: int, ride_id: int|null, passenger_id: int|null, departure_time: string|null}  $row
     */
    private function notifyPassenger(NotificationPort $notifications, array $row): bool
    {
        try {
            $user = $row['passenger_id'] === null ? null : User::find($row['passenger_id']);

            if (! $user) {
                return false;
            }

            $notifications->createNotification(
                $user,
                'booking_expired',
                'انتهت صلاحية طلب الحجز',
                'لم يرد السائق على طلب الحجز قبل موعد الرحلة، لذلك تم إلغاء الطلب تلقائياً. '.
                'لم يتم خصم أي مبلغ من محفظتك.',
                ['booking_id' => $row['booking_id'], 'ride_id' => $row['ride_id']],
                'normal',
                'ride'
            );

            return true;
        } catch (\Throwable $e) {
            Log::warning('Could not notify passenger of expired booking', [
                'booking_id' => $row['booking_id'],
                'error' => SafeExceptionLog::message($e),
            ]);

            return false;
        }
    }

    private function reportSummary(int $expired, int $notified): void
    {
        $this->info(sprintf(
            'Expired %d booking request(s); %d passenger(s) notified.',
            $expired,
            $notified
        ));
    }
}
