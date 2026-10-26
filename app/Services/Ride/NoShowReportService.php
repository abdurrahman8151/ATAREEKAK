<?php

namespace App\Services\Ride;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\NoshowReport;
use App\Models\Ride;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Scenario A and B: the driver reports a passenger who did not show up, the passenger reports a
 * driver who did not show up, and the counter-report that turns either case into a conflict.
 *
 * Split out of Noshowservice (behaviour unchanged). Replies are built by the same responders as
 * before, so the response shape, the messages and the notifications are untouched. Bodies moved
 * verbatim.
 */
final class NoShowReportService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly NoShowConflictService $conflicts,
        private readonly NoShowReportGuard $guard,
    ) {}

    public function reportPassengerNoShow(int $bookingId, User $driver): array
    {
        return DB::transaction(function () use ($bookingId, $driver) {

            $booking = Booking::lockForUpdate()->with(['ride', 'user'])->findOrFail($bookingId);
            $ride = $booking->ride;
            $passenger = $booking->user;

            $this->guard->assertDriverMayReportPassengerNoShow($booking, $ride, $driver, $bookingId);

            // CONFLICT CHECK: has this passenger already reported the driver?
            $counterReport = $this->findCounterReport(
                $ride,
                $passenger->id,
                'passenger',
                $driver->id,
                'driver'
            );

            if ($counterReport) {
                // SCENARIO C: CONFLICT
                return $this->conflicts->handleConflict(
                    existingReport: $counterReport,
                    driver: $driver,
                    passenger: $passenger,
                    booking: $booking,
                    ride: $ride,
                    newReporterRole: 'driver',
                );
            }

            // SCENARIO A: pending driver report
            return $this->openDriverReport($ride, $booking, $driver, $passenger);
        }, attempts: 3);
    }

    public function reportDriverNoShow(int $rideId, User $passenger): array
    {
        return DB::transaction(function () use ($rideId, $passenger) {

            $ride = Ride::lockForUpdate()->with('driver')->findOrFail($rideId);
            $driver = $ride->driver;

            $booking = $this->findPassengerConfirmedBooking($ride, $passenger);

            $this->guard->assertPassengerMayReportDriverNoShow($ride, $passenger);

            // CONFLICT CHECK: has the driver already reported this passenger?
            $counterReport = $this->findCounterReport(
                $ride,
                $driver->id,
                'driver',
                $passenger->id,
                'passenger'
            );

            if ($counterReport) {
                // SCENARIO C: CONFLICT
                return $this->conflicts->handleConflict(
                    existingReport: $counterReport,
                    driver: $driver,
                    passenger: $passenger,
                    booking: $booking,
                    ride: $ride,
                    newReporterRole: 'passenger',
                );
            }

            // SCENARIO B: pending passenger report
            return $this->openPassengerReport($ride, $booking, $passenger, $driver);
        }, attempts: 3);
    }

    /**
     * SCENARIO A: the driver opens a pending report against the passenger.
     *
     * @return array{message: string, report_id: int, expires_at: string, conflict: bool}
     */
    private function openDriverReport(Ride $ride, Booking $booking, User $driver, User $passenger): array
    {
        $report = $this->createPendingNoShowReport(
            $ride,
            $booking,
            $driver->id,
            'driver',
            $passenger->id,
            'passenger'
        );

        $this->notifyPassengerOfDriverReport($passenger, $driver, $booking, $ride, $report);

        Log::info('Driver filed passenger no-show report', [
            'report_id' => $report->id,
            'driver_id' => $driver->id,
            'passenger_id' => $passenger->id,
            'booking_id' => $booking->id,
            'expires_at' => $report->expires_at,
        ]);

        return $this->noShowReportResponse(
            $report,
            'No-show report submitted. The passenger has 2 hours to dispute. If no dispute, the penalty is applied automatically.'
        );
    }

    /**
     * SCENARIO B: the passenger opens a pending report against the driver.
     *
     * @return array{message: string, report_id: int, expires_at: string, conflict: bool}
     */
    private function openPassengerReport(Ride $ride, Booking $booking, User $passenger, User $driver): array
    {
        $report = $this->createPendingNoShowReport(
            $ride,
            $booking,
            $passenger->id,
            'passenger',
            $driver->id,
            'driver'
        );

        $this->notifyDriverOfPassengerReport($driver, $passenger, $ride, $report);

        Log::info('Passenger filed driver no-show report', [
            'report_id' => $report->id,
            'passenger_id' => $passenger->id,
            'driver_id' => $driver->id,
            'booking_id' => $booking->id,
            'expires_at' => $report->expires_at,
        ]);

        return $this->noShowReportResponse(
            $report,
            'No-show report submitted. The driver has 2 hours to dispute. If no dispute, the penalty is applied automatically.'
        );
    }

    /**
     * The submitted-report envelope shared by both reporters (no conflict).
     *
     * @return array{message: string, report_id: int, expires_at: string, conflict: bool}
     */
    private function noShowReportResponse(NoshowReport $report, string $message): array
    {
        return [
            'message' => $message,
            'report_id' => $report->id,
            'expires_at' => $report->expires_at->toIso8601String(),
            'conflict' => false,
        ];
    }

    /**
     * The still-live counter-report from the other party, if any (Scenario C trigger).
     */
    private function findCounterReport(
        Ride $ride,
        int $reporterId,
        string $reporterRole,
        int $targetId,
        string $targetRole
    ): ?NoshowReport {
        return NoshowReport::where('ride_id', $ride->id)
            ->where('reporter_id', $reporterId)
            ->where('reporter_role', $reporterRole)
            ->where('target_id', $targetId)
            ->where('target_role', $targetRole)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->first();
    }

    /** The pending report the reporter is about to open (Scenarios A and B). */
    private function createPendingNoShowReport(
        Ride $ride,
        Booking $booking,
        int $reporterId,
        string $reporterRole,
        int $targetId,
        string $targetRole
    ): NoshowReport {
        return NoshowReport::create([
            'ride_id' => $ride->id,
            'booking_id' => $booking->id,
            'reporter_id' => $reporterId,
            'reporter_role' => $reporterRole,
            'target_id' => $targetId,
            'target_role' => $targetRole,
            'payment_method' => $ride->payment_method,
            'status' => 'pending',
            'expires_at' => now()->addHours($this->guard->disputeHours()),
        ]);
    }

    /** The reporter's own confirmed booking on the ride, locked. */
    private function findPassengerConfirmedBooking(Ride $ride, User $passenger): Booking
    {
        $booking = Booking::lockForUpdate()
            ->where('ride_id', $ride->id)
            ->where('user_id', $passenger->id)
            ->where('status', BookingStatus::CONFIRMED->value)
            ->first();

        if (! $booking) {
            throw new \InvalidArgumentException('No confirmed booking found for you on this ride.');
        }

        return $booking;
    }

    /** Tell the passenger the driver reported them, with the 2-hour contest window. */
    private function notifyPassengerOfDriverReport(
        User $passenger,
        User $driver,
        Booking $booking,
        Ride $ride,
        NoshowReport $report
    ): void {
        try {
            $this->notificationService->createNotification(
                $passenger,
                'noshow_driver_reported_you',
                'تقرير غياب — لديك ساعتان للاعتراض',
                "أفاد السائق {$driver->first_name} {$driver->last_name} بأنك لم تصل في موعد الرحلة. "
                .'إذا كان السائق هو من لم يحضر، اضغط زر «السائق لم يحضر» قبل انتهاء ساعتين.',
                ['booking_id' => $booking->id, 'ride_id' => $ride->id, 'report_id' => $report->id],
                'high',
                'ride'
            );
        } catch (\Throwable $e) {
            Log::warning('no-show notification dispatch failed (non-fatal): '.$e->getMessage());
        }
    }

    /** Tell the driver the passenger reported them, with the 2-hour contest window. */
    private function notifyDriverOfPassengerReport(
        User $driver,
        User $passenger,
        Ride $ride,
        NoshowReport $report
    ): void {
        try {
            $this->notificationService->createNotification(
                $driver,
                'noshow_passenger_reported_you',
                'تقرير غياب — لديك ساعتان للاعتراض',
                "أفاد الراكب {$passenger->first_name} {$passenger->last_name} بأنك لم تحضر للرحلة. "
                .'إذا كان الراكب هو من لم يصل، اضغط زر «الراكب لم يحضر» على الحجز المعني قبل انتهاء ساعتين.',
                ['ride_id' => $ride->id, 'report_id' => $report->id],
                'high',
                'ride'
            );
        } catch (\Throwable $e) {
            Log::warning('no-show notification dispatch failed (non-fatal): '.$e->getMessage());
        }
    }
}
