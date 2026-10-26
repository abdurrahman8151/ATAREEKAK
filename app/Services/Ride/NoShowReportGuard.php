<?php

namespace App\Services\Ride;

use App\Enums\BookingStatus;
use App\Enums\RideStatus;
use App\Models\Booking;
use App\Models\NoshowReport;
use App\Models\Ride;
use App\Models\User;

/**
 * The no-show windows and the four guards the reporting flow runs before it opens a report:
 * the gate (how long after departure the button unlocks), the dispute window (how long the
 * counter-report stays open), who may report, that the ride is live, and that no report is
 * already pending.
 *
 * Split out of Noshowservice (behaviour unchanged), then out of NoShowReportService as an injected
 * collaborator (batch 227) so the service that owns the persistence no longer also holds the guard
 * logic. Bodies moved verbatim.
 */
final class NoShowReportGuard
{
    // AF-4 (app-future audit): GATE_MINUTES=1 / DISPUTE_MINUTES=2 were testing
    // values with the real hours commented out above them — a two-minute
    // dispute window shipped to production, and money-path timings that a
    // deploy could not tune without editing code. The real 1h/2h defaults now
    // live in config/rides.php (rides.noshow.*); see the config comment.

    private function gateHours(): float
    {
        return (float) config('rides.noshow.gate_hours', 1);
    }

    public function disputeHours(): float
    {
        return (float) config('rides.noshow.dispute_hours', 2);
    }

    /**
     * Everything the driver path must clear before a report may be opened: ownership,
     * the booking still being confirmed, a live ride, the time gate, and no duplicate.
     */
    public function assertDriverMayReportPassengerNoShow(
        Booking $booking,
        Ride $ride,
        User $driver,
        int $bookingId
    ): void {
        $this->assertDriverOwnsRide($ride, $driver);

        if ($booking->status !== BookingStatus::CONFIRMED->value) {
            throw new \InvalidArgumentException(
                "Booking #{$bookingId} is not in 'confirmed' status — cannot report no-show."
            );
        }

        $this->assertRideIsLive($ride);
        $this->assertNoShowGateOpen($ride);
        $this->assertNoExistingReport(
            $ride,
            $driver->id,
            'driver',
            $booking,
            'You have already submitted a no-show report for this booking.'
        );
    }

    /**
     * Everything the passenger path must clear before a report may be opened: a live
     * ride, the time gate, and no duplicate report from the same passenger on the ride.
     */
    public function assertPassengerMayReportDriverNoShow(Ride $ride, User $passenger): void
    {
        $this->assertRideIsLive($ride);
        $this->assertNoShowGateOpen($ride);
        $this->assertNoExistingReport(
            $ride,
            $passenger->id,
            'passenger',
            null,
            'You have already submitted a no-show report for this ride.'
        );
    }

    /** Only the ride's own driver may report no-shows for it. */
    private function assertDriverOwnsRide(Ride $ride, User $driver): void
    {
        if ((int) $ride->driver_id !== (int) $driver->id) {
            throw new \InvalidArgumentException('You can only report no-shows for your own rides.');
        }
    }

    /** A report is only accepted while the ride is still live. */
    private function assertRideIsLive(Ride $ride): void
    {
        if (! in_array($ride->status, [
            RideStatus::ACTIVE->value,
            RideStatus::FULL->value,
            RideStatus::LAUNCHED->value,
        ])) {
            throw new \InvalidArgumentException(
                "Cannot report no-show for a ride with status: {$ride->status}."
            );
        }
    }

    /** TIME GATE: config hours after departure (default 1h). */
    private function assertNoShowGateOpen(Ride $ride): void
    {
        $gateOpensAt = $ride->departure_time->copy()->addHours($this->gateHours());

        if (now()->lt($gateOpensAt)) {
            $remaining = now()->diffInSeconds($gateOpensAt);
            throw new \InvalidArgumentException(
                'No-show reporting unlocks '.$this->gateHours()." hour(s) after departure. {$remaining} second(s) remaining."
            );
        }
    }

    /**
     * Prevent a duplicate report from the same reporter.
     *
     * The driver path additionally scopes the check to the booking it is reporting on;
     * the passenger path is scoped to the ride. $booking = null skips the booking scope.
     */
    private function assertNoExistingReport(
        Ride $ride,
        int $reporterId,
        string $reporterRole,
        ?Booking $booking,
        string $message
    ): void {
        $query = NoshowReport::where('ride_id', $ride->id)
            ->where('reporter_id', $reporterId)
            ->where('reporter_role', $reporterRole)
            ->whereIn('status', ['pending', 'disputed']);

        if ($booking) {
            $query->where('booking_id', $booking->id);
        }

        if ($query->exists()) {
            throw new \InvalidArgumentException($message);
        }
    }
}
