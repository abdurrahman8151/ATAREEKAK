<?php

namespace App\Services\Ride;

use App\Enums\BookingStatus;
use App\Enums\ComplaintStatus;
use App\Enums\ComplaintType;
use App\Enums\RideStatus;
use App\Models\Booking;
use App\Models\Complaint;
use App\Models\NoshowReport;
use App\Models\Ride;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Payment\NoShowSettlement;
use App\Services\Score\ScoreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PLACE IN: app/Services/Ride/NoshowService.php
 *
 * ══════════════════════════════════════════════════════════════════════════════
 *  THREE SCENARIOS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *  Gate rule: no-show button unlocks 1 hour after departure_time.
 *
 *  Scenario A — Driver reports passenger P didn't show up:
 *    1. Driver presses "no-show" on booking B  → pending report, expires +2h.
 *    2a. Passenger does NOT dispute within 2h  → scheduler resolves: passenger
 *        is penalised (score −15; e-pay: escrow → driver 95% + primary 5%).
 *    2b. Passenger presses "driver no-show" within 2h → CONFLICT → auto-complaint.
 *
 *  Scenario B — Passenger reports driver didn't show up:
 *    1. Passenger presses "driver no-show" on ride R → pending report, expires +2h.
 *    2a. Driver does NOT dispute within 2h → scheduler resolves: driver is
 *        penalised (score −15; e-pay: escrow → passenger 100% refund).
 *    2b. Driver presses "passenger no-show" on that booking within 2h → CONFLICT → auto-complaint.
 *
 *  Scenario C — CONFLICT (both pressed within 2 hours):
 *    → Both NoshowReport rows marked 'disputed'.
 *    → Complaint of type 'no_show' auto-created with full context.
 *    → Support team decides manually.
 *    → No automatic penalty applied.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 */
final class Noshowservice
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

    private function disputeHours(): float
    {
        return (float) config('rides.noshow.dispute_hours', 2);
    }

    public function __construct(
        private readonly ScoreService $scoreService,
        private readonly NoShowSettlement $noShowSettlement,
        private readonly NotificationService $notificationService,
    ) {}

    // =========================================================================
    //  DRIVER reports that a PASSENGER did not show up
    //  Called by: BookingService::reportPassengerNoShow (delegated here)
    //  Route:     POST /api/bookings/{bookingId}/passenger-no-show
    // =========================================================================
    public function reportPassengerNoShow(int $bookingId, User $driver): array
    {
        return DB::transaction(function () use ($bookingId, $driver) {

            $booking = Booking::lockForUpdate()->with(['ride', 'user'])->findOrFail($bookingId);
            $ride = $booking->ride;
            $passenger = $booking->user;

            $this->assertDriverMayReportPassengerNoShow($booking, $ride, $driver, $bookingId);

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
                return $this->handleConflict(
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

    // =========================================================================
    //  PASSENGER reports that the DRIVER did not show up
    //  Called by: RideService::reportDriverNoShow (delegated here)
    //  Route:     POST /api/rides/{rideId}/driver-no-show
    // =========================================================================
    public function reportDriverNoShow(int $rideId, User $passenger): array
    {
        return DB::transaction(function () use ($rideId, $passenger) {

            $ride = Ride::lockForUpdate()->with('driver')->findOrFail($rideId);
            $driver = $ride->driver;

            $booking = $this->findPassengerConfirmedBooking($ride, $passenger);

            $this->assertPassengerMayReportDriverNoShow($ride, $passenger);

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
                return $this->handleConflict(
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

    // =========================================================================
    //  SCHEDULED RESOLUTION
    //  Called by: Artisan command `noshow:resolve` (every 15 min via Kernel)
    // =========================================================================
    public function resolveExpiredReports(): int
    {
        $expired = NoshowReport::where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->with(['ride.driver', 'booking.user'])
            ->get();

        $resolved = 0;

        foreach ($expired as $report) {
            try {
                $applied = $this->resolveExpiredReport($report);

                if (! $applied) {
                    continue;
                }

                if ($applied === 'voided') {
                    // Terminal: the booking was already settled/reset elsewhere, so
                    // no money moved. A void is not counted as a resolution, so the
                    // caller can tell "nothing to do" from "settled".
                    Log::info('No-show report voided: booking was no longer confirmed', [
                        'report_id' => $report->id,
                        'target_role' => $report->target_role,
                    ]);

                    continue;
                }

                $resolved++;

                Log::info('No-show report auto-resolved', [
                    'report_id' => $report->id,
                    'target_role' => $report->target_role,
                    'target_id' => $report->target_id,
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to resolve no-show report', [
                    'report_id' => $report->id,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        }

        return $resolved;
    }

    /**
     * Resolve one expired report inside its own transaction.
     *
     * Returns true when the reporter won the settlement, 'voided' when the booking was
     * already settled elsewhere (no money moved), or null when another resolver won the
     * row first and there is nothing to do.
     */
    private function resolveExpiredReport(NoshowReport $report): bool|string|null
    {
        return DB::transaction(function () use ($report) {
            // T3-16: the report was fetched OUTSIDE this transaction and
            // never re-read, so applyPenalty() - which moves real money
            // (escrow -> driver 95% / primary 5%) and flips booking
            // status - ran with no lock and no status re-check. Correctness
            // depended entirely on the scheduler's withoutOverlapping() +
            // onOneServer() topology; any second invocation path (manual
            // `noshow:resolve`, a retry, a future API trigger) could
            // double-apply a settlement. The row is now locked FOR UPDATE
            // and its status re-checked inside the transaction, making the
            // operation idempotent by construction: a concurrent resolver
            // either blocks here or sees status != pending and skips.
            /** @var NoshowReport|null $locked */
            $locked = NoshowReport::whereKey($report->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status !== 'pending') {
                return null; // resolved/disputed meanwhile - skip
            }

            // RV-02 (L1): applyPenalty() returns false when the booking is
            // no longer `confirmed` (already released/cancelled by another
            // path). The report is then terminal as `void` rather than
            // staying `pending`, which is what made the scheduler retry the
            // same settlement every minute (R2 sec 1.3).
            if (! $this->applyPenalty($locked)) {
                $locked->update([
                    'status' => 'void',
                    'resolved_at' => now(),
                ]);

                return 'voided';
            }

            $locked->update([
                'status' => 'resolved_reporter_wins',
                'resolved_at' => now(),
            ]);

            return true;
        }, attempts: 3);
    }

    // =========================================================================
    //  PRIVATE — CONFLICT HANDLER (Scenario C)
    // =========================================================================

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
     * Everything the driver path must clear before a report may be opened: ownership,
     * the booking still being confirmed, a live ride, the time gate, and no duplicate.
     */
    private function assertDriverMayReportPassengerNoShow(
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
    private function assertPassengerMayReportDriverNoShow(Ride $ride, User $passenger): void
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
            'expires_at' => now()->addHours($this->disputeHours()),
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

    /**
     * Both parties filed a report within the 2-hour window.
     * Neither is automatically penalised.
     * A `no_show` complaint is created for the support team.
     */
    private function handleConflict(
        NoshowReport $existingReport,
        User $driver,
        User $passenger,
        Booking $booking,
        Ride $ride,
        string $newReporterRole,   // 'driver' | 'passenger'
    ): array {
        $this->recordDisputedCounterReport($existingReport, $driver, $passenger, $booking, $ride, $newReporterRole);

        $complaint = $this->createConflictComplaint($driver, $passenger, $booking, $ride, $existingReport, $newReporterRole);

        $this->notifyConflictParties($driver, $passenger, $complaint, $ride);

        Log::info('No-show conflict detected — auto-complaint created', [
            'ride_id' => $ride->id,
            'booking_id' => $booking->id,
            'driver_id' => $driver->id,
            'passenger_id' => $passenger->id,
            'complaint_id' => $complaint->id,
        ]);

        return [
            'message' => 'Both parties filed a no-show report. A support complaint has been opened automatically. No automatic penalty will be applied — the support team will investigate.',
            'conflict' => true,
            'complaint_id' => $complaint->id,
        ];
    }

    // =========================================================================
    //  PRIVATE — PENALTY APPLICATION (Scenarios A and B, after 2h expiry)
    // =========================================================================

    /**
     * Mark the existing report disputed and record the incoming one as disputed too,
     * so both sides of the conflict stay in the audit trail.
     */
    private function recordDisputedCounterReport(
        NoshowReport $existingReport,
        User $driver,
        User $passenger,
        Booking $booking,
        Ride $ride,
        string $newReporterRole
    ): void {
        $existingReport->update([
            'status' => 'disputed',
            'resolved_at' => now(),
        ]);

        $newReporterId = $newReporterRole === 'driver' ? $driver->id : $passenger->id;
        $newTargetId = $newReporterRole === 'driver' ? $passenger->id : $driver->id;
        $newTargetRole = $newReporterRole === 'driver' ? 'passenger' : 'driver';

        NoshowReport::create([
            'ride_id' => $ride->id,
            'booking_id' => $booking->id,
            'reporter_id' => $newReporterId,
            'reporter_role' => $newReporterRole,
            'target_id' => $newTargetId,
            'target_role' => $newTargetRole,
            'payment_method' => $ride->payment_method,
            'status' => 'disputed',
            'expires_at' => now(),
            'resolved_at' => now(),
        ]);
    }

    /** Auto-create the no_show support complaint the conflict is handed to. */
    private function createConflictComplaint(
        User $driver,
        User $passenger,
        Booking $booking,
        Ride $ride,
        NoshowReport $existingReport,
        string $newReporterRole
    ): Complaint {
        return Complaint::create([
            'user_id' => $passenger->id,      // passenger as the "submitter"
            'complained_id' => $driver->id,         // driver as the "respondent"
            'type' => ComplaintType::NO_SHOW->value,
            'title' => 'تعارض تقارير الغياب — يحتاج تحقيقاً',
            'description' => $this->buildConflictDescription($driver, $passenger, $booking, $ride, $existingReport, $newReporterRole),
            'status' => ComplaintStatus::PENDING->value,
            'ride_id' => $ride->id,
        ]);
    }

    /** Notify both parties that the conflict is now with the support team. */
    private function notifyConflictParties(
        User $driver,
        User $passenger,
        Complaint $complaint,
        Ride $ride
    ): void {
        try {
            $this->notificationService->createNotification(
                $passenger,
                'noshow_conflict',
                'تعارض تقارير الغياب',
                'كلا الطرفين ضغطا زر الغياب. تم فتح شكوى تلقائية وسيتدخل فريق الدعم للبت في الأمر. لن تُطبَّق أي عقوبة تلقائية.',
                ['ride_id' => $ride->id, 'complaint_id' => $complaint->id],
                'high',
                'system'
            );
            $this->notificationService->createNotification(
                $driver,
                'noshow_conflict',
                'تعارض تقارير الغياب',
                'كلا الطرفين ضغطا زر الغياب. تم فتح شكوى تلقائية وسيتدخل فريق الدعم للبت في الأمر. لن تُطبَّق أي عقوبة تلقائية.',
                ['ride_id' => $ride->id, 'complaint_id' => $complaint->id],
                'high',
                'system'
            );
        } catch (\Throwable $e) {
            Log::warning('no-show notification dispatch failed (non-fatal): '.$e->getMessage());
        }
    }

    /**
     * The 2-hour window expired with no counter-report.
     * The reporter wins. The target is penalised.
     */
    private function applyPenalty(NoshowReport $report): bool
    {
        // RV-02 (L1): re-check the BOOKING inside the locked transaction.
        // resolveExpiredReports() already locks and re-checks the REPORT (T3-16),
        // but the booking was loaded here with no lock and no status check, so a
        // settlement ran even when the booking had already been settled by another
        // path — the passenger confirming completion (escrow already released
        // 95/5) or any cancel flow. applyPenalty() then flipped the status to
        // `no_show` and moved the same fare a second time out of SyCash, i.e. out
        // of OTHER bookings' escrow. Require `confirmed`: the caller voids the
        // report instead, so a settled booking can never be re-settled.
        $booking = Booking::lockForUpdate()->with(['ride', 'user'])->findOrFail($report->booking_id);

        if ($booking->status !== BookingStatus::CONFIRMED->value) {
            Log::warning('No-show penalty skipped: booking is no longer confirmed', [
                'report_id' => $report->id,
                'booking_id' => $booking->id,
                'booking_status' => $booking->status,
            ]);

            return false;
        }

        $ride = $booking->ride;
        $passenger = User::findOrFail($report->target_role === 'passenger' ? $report->target_id : $report->reporter_id);
        $driver = User::findOrFail($ride->driver_id);

        if ($report->target_role === 'passenger') {
            $this->penalisePassenger($report, $booking, $ride, $passenger, $driver);
        } else {
            $this->penaliseDriver($report, $booking, $ride, $passenger, $driver);
        }

        return true;
    }

    // =========================================================================
    //  PRIVATE — COMPLAINT DESCRIPTION BUILDER
    // =========================================================================

    private function buildConflictDescription(
        User $driver,
        User $passenger,
        Booking $booking,
        Ride $ride,
        NoshowReport $firstReport,
        string $secondReporterRole,
    ): string {
        $firstRole = $firstReport->reporter_role === 'driver' ? 'السائق' : 'الراكب';
        $secondRole = $secondReporterRole === 'driver' ? 'السائق' : 'الراكب';

        return implode("\n", [
            '═══════════════════════════════════════════',
            ' تعارض تقارير الغياب — شكوى تلقائية',
            '═══════════════════════════════════════════',
            '',
            'ضغط كلا الطرفين زر الغياب خلال نافذة ساعتين.',
            'لم تُطبَّق أي عقوبة تلقائية. يُرجى التحقيق وتطبيق العقوبة يدوياً.',
            '',
            '── تفاصيل الرحلة ─────────────────────────',
            "  رقم الرحلة    : #{$ride->id}",
            "  من            : {$ride->pickup_address}",
            "  إلى           : {$ride->destination_address}",
            "  موعد الانطلاق: {$ride->departure_time->format('Y-m-d H:i')}",
            "  طريقة الدفع  : {$ride->payment_method}",
            '',
            '── الأطراف ────────────────────────────────',
            "  السائق  : {$driver->first_name} {$driver->last_name} (ID: {$driver->id})",
            "  الراكب  : {$passenger->first_name} {$passenger->last_name} (ID: {$passenger->id})",
            "  رقم الحجز: #{$booking->id} — {$booking->seats} مقعد",
            '',
            '── التسلسل الزمني ─────────────────────────',
            "  أول تقرير : {$firstRole} — {$firstReport->created_at->format('Y-m-d H:i:s')}",
            "  ثاني تقرير: {$secondRole} — ".now()->format('Y-m-d H:i:s').' (هذا التقرير)',
            "  نافذة النزاع: {$firstReport->expires_at->format('Y-m-d H:i:s')}",
            '',
            '── المطلوب من فريق الدعم ──────────────────',
            '  1. التحقق من سجلات الموقع / وقت دخول التطبيق',
            '  2. التواصل مع الطرفين إن لزم',
            '  3. تطبيق العقوبة على الطرف المخطئ يدوياً',
        ]);
    }

    /** Driver won: the passenger is penalised (score, e-pay escrow to driver, notifications). */
    private function penalisePassenger(NoshowReport $report, Booking $booking, Ride $ride, User $passenger, User $driver): void
    {
        // ── Driver won → passenger is penalised ───────────────────────────
        $booking->update(['status' => 'no_show', 'completed_at' => now()]);

        // Score: −15 for cash; e-pay = 0 pts (wallet handles the punishment)
        $this->scoreService->recordPassengerNoShow($passenger, $booking, $report->payment_method);

        // Wallet: escrow → driver 95% + primary 5% (e-pay only)
        if ($report->payment_method === 'e-pay') {
            $this->noShowSettlement->processPassengerNoShow($booking, $ride, $passenger);
        }

        // Notify passenger: penalty applied
        try {
            $this->notificationService->createNotification(
                $passenger,
                'noshow_penalty_applied',
                'تم تطبيق عقوبة الغياب',
                'انتهت مهلة الاعتراض ولم تعترض. تم تطبيق عقوبة الغياب على حسابك.',
                ['booking_id' => $booking->id],
                'high',
                'system'
            );
        } catch (\Throwable $e) {
            Log::warning('no-show notification dispatch failed (non-fatal): '.$e->getMessage());
        }

        // Notify driver: you received your earnings
        try {
            $this->notificationService->createNotification(
                $driver,
                'noshow_resolved_in_your_favor',
                'تم البت في تقرير الغياب',
                'تم تأكيد غياب الراكب وتطبيق العقوبة المقررة.'.
                ($report->payment_method === 'e-pay' ? ' تم تحويل المبلغ إلى محفظتك.' : ''),
                ['booking_id' => $booking->id],
                'normal',
                'ride'
            );
        } catch (\Throwable $e) {
            Log::warning('no-show notification dispatch failed (non-fatal): '.$e->getMessage());
        }
    }

    /** Passenger won: the driver is penalised (score, e-pay refund to passenger, notifications). */
    private function penaliseDriver(NoshowReport $report, Booking $booking, Ride $ride, User $passenger, User $driver): void
    {
        // ── Passenger won → driver is penalised ───────────────────────────
        $booking->update(['status' => 'no_show', 'completed_at' => now()]);

        // Score: −15 for cash; e-pay = 0 pts (wallet handles the punishment)
        $this->scoreService->recordDriverNoShow($driver, $ride, $report->payment_method);

        // Wallet: escrow → passenger 100% refund (e-pay only)
        if ($report->payment_method === 'e-pay') {
            $this->noShowSettlement->processDriverNoShowRefund($ride, $booking, $passenger);
        }

        // Notify driver: penalty applied
        try {
            $this->notificationService->createNotification(
                $driver,
                'noshow_penalty_applied',
                'تم تطبيق عقوبة الغياب',
                'انتهت مهلة الاعتراض ولم تعترض. تم تطبيق عقوبة الغياب على حسابك.',
                ['ride_id' => $ride->id],
                'high',
                'system'
            );
        } catch (\Throwable $e) {
            Log::warning('no-show notification dispatch failed (non-fatal): '.$e->getMessage());
        }

        // Notify passenger: refunded (e-pay) or resolved (cash)
        try {
            $this->notificationService->createNotification(
                $passenger,
                'noshow_resolved_in_your_favor',
                'تم البت في تقرير الغياب',
                'تم تأكيد غياب السائق وتطبيق العقوبة المقررة.'.
                ($report->payment_method === 'e-pay' ? ' تم استرجاع المبلغ إلى محفظتك.' : ''),
                ['booking_id' => $booking->id],
                'normal',
                'ride'
            );
        } catch (\Throwable $e) {
            Log::warning('no-show notification dispatch failed (non-fatal): '.$e->getMessage());
        }
    }
}
