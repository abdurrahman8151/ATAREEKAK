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
use App\Services\Payment\WalletTransactionService;
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
final class NoshowService
{
    // Gate: button unlocks this many hours after departure
    // private const GATE_HOURS = 1;

    // Window: the other party has this many hours to file a counter-report
    //   private const DISPUTE_HOURS = 2;
    // AFTER (testing mode)
    private const GATE_MINUTES    = 1;
    private const DISPUTE_MINUTES = 2;

    public function __construct(
        private readonly ScoreService             $scoreService,
        private readonly WalletTransactionService  $walletService,
        private readonly NotificationService       $notificationService,
    ) {}

    // =========================================================================
    //  DRIVER reports that a PASSENGER did not show up
    //  Called by: BookingService::reportPassengerNoShow (delegated here)
    //  Route:     POST /api/bookings/{bookingId}/passenger-no-show
    // =========================================================================
    public function reportPassengerNoShow(int $bookingId, User $driver): array
    {
        return DB::transaction(function () use ($bookingId, $driver) {

            $booking   = Booking::lockForUpdate()->with(['ride', 'user'])->findOrFail($bookingId);
            $ride      = $booking->ride;
            $passenger = $booking->user;

            // ── Authorization ─────────────────────────────────────────────────
            if ((int) $ride->driver_id !== (int) $driver->id) {
                throw new \InvalidArgumentException('You can only report no-shows for your own rides.');
            }

            // ── Booking must still be active ──────────────────────────────────
            if ($booking->status !== BookingStatus::CONFIRMED->value) {
                throw new \InvalidArgumentException(
                    "Booking #{$bookingId} is not in 'confirmed' status — cannot report no-show."
                );
            }

            // ── Ride must be in a live status ─────────────────────────────────
            if (!in_array($ride->status, [
                RideStatus::ACTIVE->value,
                RideStatus::FULL->value,
                RideStatus::LAUNCHED->value,
            ])) {
                throw new \InvalidArgumentException(
                    "Cannot report no-show for a ride with status: {$ride->status}."
                );
            }

            // ── TIME GATE: 1 hour after departure ────────────────────────────
            $gateOpensAt = $ride->departure_time->copy()->addMinutes(self::GATE_MINUTES);
            if (now()->lt($gateOpensAt)) {
                $remaining = now()->diffInSeconds($gateOpensAt);
                throw new \InvalidArgumentException(
                    "No-show reporting unlocks " . self::GATE_MINUTES . " minute(s) after departure. {$remaining} second(s) remaining."
                );
            }

            // ── Prevent duplicate report from same driver on same booking ─────
            $alreadyReported = NoshowReport::where('ride_id', $ride->id)
                ->where('booking_id', $booking->id)
                ->where('reporter_id', $driver->id)
                ->where('reporter_role', 'driver')
                ->whereIn('status', ['pending', 'disputed'])
                ->exists();

            if ($alreadyReported) {
                throw new \InvalidArgumentException('You have already submitted a no-show report for this booking.');
            }

            // ── CONFLICT CHECK: has this passenger already reported the driver? ─
            $counterReport = NoshowReport::where('ride_id', $ride->id)
                ->where('reporter_id', $passenger->id)
                ->where('reporter_role', 'passenger')
                ->where('target_id', $driver->id)
                ->where('target_role', 'driver')
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($counterReport) {
                // ── SCENARIO C: CONFLICT ──────────────────────────────────────
                return $this->handleConflict(
                    existingReport: $counterReport,
                    driver:         $driver,
                    passenger:      $passenger,
                    booking:        $booking,
                    ride:           $ride,
                    newReporterRole:'driver',
                );
            }

            // ── SCENARIO A: pending driver report ─────────────────────────────
            $report = NoshowReport::create([
                'ride_id'        => $ride->id,
                'booking_id'     => $booking->id,
                'reporter_id'    => $driver->id,
                'reporter_role'  => 'driver',
                'target_id'      => $passenger->id,
                'target_role'    => 'passenger',
                'payment_method' => $ride->payment_method,
                'status'         => 'pending',
                'expires_at'     => now()->addMinutes(self::DISPUTE_MINUTES),
            ]);

            // Notify passenger: you have 2 hours to contest
            try {
                $this->notificationService->createNotification(
                    $passenger,
                    'noshow_driver_reported_you',
                    'تقرير غياب — لديك ساعتان للاعتراض',
                    "أفاد السائق {$driver->first_name} {$driver->last_name} بأنك لم تصل في موعد الرحلة. "
                    . "إذا كان السائق هو من لم يحضر، اضغط زر «السائق لم يحضر» قبل انتهاء ساعتين.",
                    ['booking_id' => $booking->id, 'ride_id' => $ride->id, 'report_id' => $report->id],
                    'high',
                    'ride'
                );
            } catch (\Throwable) {}

            Log::info('Driver filed passenger no-show report', [
                'report_id'    => $report->id,
                'driver_id'    => $driver->id,
                'passenger_id' => $passenger->id,
                'booking_id'   => $booking->id,
                'expires_at'   => $report->expires_at,
            ]);

            return [
                'message'    => 'No-show report submitted. The passenger has 2 hours to dispute. If no dispute, the penalty is applied automatically.',
                'report_id'  => $report->id,
                'expires_at' => $report->expires_at->toIso8601String(),
                'conflict'   => false,
            ];
        });
    }

    // =========================================================================
    //  PASSENGER reports that the DRIVER did not show up
    //  Called by: RideService::reportDriverNoShow (delegated here)
    //  Route:     POST /api/rides/{rideId}/driver-no-show
    // =========================================================================
    public function reportDriverNoShow(int $rideId, User $passenger): array
    {
        return DB::transaction(function () use ($rideId, $passenger) {

            $ride   = Ride::lockForUpdate()->with('driver')->findOrFail($rideId);
            $driver = $ride->driver;

            // ── Find this passenger's booking ─────────────────────────────────
            $booking = Booking::lockForUpdate()
                ->where('ride_id', $ride->id)
                ->where('user_id', $passenger->id)
                ->where('status', BookingStatus::CONFIRMED->value)
                ->first();

            if (!$booking) {
                throw new \InvalidArgumentException('No confirmed booking found for you on this ride.');
            }

            // ── Ride must be in a live status ─────────────────────────────────
            if (!in_array($ride->status, [
                RideStatus::ACTIVE->value,
                RideStatus::FULL->value,
                RideStatus::LAUNCHED->value,
            ])) {
                throw new \InvalidArgumentException(
                    "Cannot report no-show for a ride with status: {$ride->status}."
                );
            }

            // ── TIME GATE: 1 hour after departure ────────────────────────────
            $gateOpensAt = $ride->departure_time->copy()->addMinutes(self::GATE_MINUTES);
            if (now()->lt($gateOpensAt)) {
                $remaining = now()->diffInSeconds($gateOpensAt);
                throw new \InvalidArgumentException(
                    "No-show reporting unlocks " . self::GATE_MINUTES . " minute(s) after departure. {$remaining} second(s) remaining."
                );
            }

            // ── Prevent duplicate report from same passenger on same ride ─────
            $alreadyReported = NoshowReport::where('ride_id', $ride->id)
                ->where('reporter_id', $passenger->id)
                ->where('reporter_role', 'passenger')
                ->whereIn('status', ['pending', 'disputed'])
                ->exists();

            if ($alreadyReported) {
                throw new \InvalidArgumentException('You have already submitted a no-show report for this ride.');
            }

            // ── CONFLICT CHECK: has the driver already reported this passenger? ─
            $counterReport = NoshowReport::where('ride_id', $ride->id)
                ->where('reporter_id', $driver->id)
                ->where('reporter_role', 'driver')
                ->where('target_id', $passenger->id)
                ->where('target_role', 'passenger')
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($counterReport) {
                // ── SCENARIO C: CONFLICT ──────────────────────────────────────
                return $this->handleConflict(
                    existingReport: $counterReport,
                    driver:         $driver,
                    passenger:      $passenger,
                    booking:        $booking,
                    ride:           $ride,
                    newReporterRole:'passenger',
                );
            }

            // ── SCENARIO B: pending passenger report ──────────────────────────
            $report = NoshowReport::create([
                'ride_id'        => $ride->id,
                'booking_id'     => $booking->id,
                'reporter_id'    => $passenger->id,
                'reporter_role'  => 'passenger',
                'target_id'      => $driver->id,
                'target_role'    => 'driver',
                'payment_method' => $ride->payment_method,
                'status'         => 'pending',
                'expires_at'     => now()->addMinutes(self::DISPUTE_MINUTES),
            ]);

            // Notify driver: passenger reported you, 2 hours to contest
            try {
                $this->notificationService->createNotification(
                    $driver,
                    'noshow_passenger_reported_you',
                    'تقرير غياب — لديك ساعتان للاعتراض',
                    "أفاد الراكب {$passenger->first_name} {$passenger->last_name} بأنك لم تحضر للرحلة. "
                    . "إذا كان الراكب هو من لم يصل، اضغط زر «الراكب لم يحضر» على الحجز المعني قبل انتهاء ساعتين.",
                    ['ride_id' => $ride->id, 'report_id' => $report->id],
                    'high',
                    'ride'
                );
            } catch (\Throwable) {}

            Log::info('Passenger filed driver no-show report', [
                'report_id'    => $report->id,
                'passenger_id' => $passenger->id,
                'driver_id'    => $driver->id,
                'booking_id'   => $booking->id,
                'expires_at'   => $report->expires_at,
            ]);

            return [
                'message'    => 'No-show report submitted. The driver has 2 hours to dispute. If no dispute, the penalty is applied automatically.',
                'report_id'  => $report->id,
                'expires_at' => $report->expires_at->toIso8601String(),
                'conflict'   => false,
            ];
        });
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
                DB::transaction(function () use ($report) {
                    $this->applyPenalty($report);

                    $report->update([
                        'status'      => 'resolved_reporter_wins',
                        'resolved_at' => now(),
                    ]);
                });
                $resolved++;

                Log::info('No-show report auto-resolved', [
                    'report_id'   => $report->id,
                    'target_role' => $report->target_role,
                    'target_id'   => $report->target_id,
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to resolve no-show report', [
                    'report_id' => $report->id,
                    'error'     => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ]);
            }
        }

        return $resolved;
    }

    // =========================================================================
    //  PRIVATE — CONFLICT HANDLER (Scenario C)
    // =========================================================================

    /**
     * Both parties filed a report within the 2-hour window.
     * Neither is automatically penalised.
     * A `no_show` complaint is created for the support team.
     */
    private function handleConflict(
        NoshowReport $existingReport,
        User         $driver,
        User         $passenger,
        Booking      $booking,
        Ride         $ride,
        string       $newReporterRole,   // 'driver' | 'passenger'
    ): array {
        // Mark the existing report as disputed
        $existingReport->update([
            'status'      => 'disputed',
            'resolved_at' => now(),
        ]);

        // Create the incoming report also as disputed (audit trail)
        $newReporterId   = $newReporterRole === 'driver' ? $driver->id   : $passenger->id;
        $newTargetId     = $newReporterRole === 'driver' ? $passenger->id : $driver->id;
        $newTargetRole   = $newReporterRole === 'driver' ? 'passenger'   : 'driver';

        NoshowReport::create([
            'ride_id'        => $ride->id,
            'booking_id'     => $booking->id,
            'reporter_id'    => $newReporterId,
            'reporter_role'  => $newReporterRole,
            'target_id'      => $newTargetId,
            'target_role'    => $newTargetRole,
            'payment_method' => $ride->payment_method,
            'status'         => 'disputed',
            'expires_at'     => now(),
            'resolved_at'    => now(),
        ]);

        // ── Auto-create the no_show complaint ─────────────────────────────────
        $complaint = Complaint::create([
            'user_id'       => $passenger->id,      // passenger as the "submitter"
            'complained_id' => $driver->id,         // driver as the "respondent"
            'type'          => ComplaintType::NO_SHOW->value,
            'title'         => 'تعارض تقارير الغياب — يحتاج تحقيقاً',
            'description'   => $this->buildConflictDescription($driver, $passenger, $booking, $ride, $existingReport, $newReporterRole),
            'status'        => ComplaintStatus::PENDING->value,
            'ride_id'       => $ride->id,
        ]);

        // Notify both parties
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
        } catch (\Throwable) {}

        Log::info('No-show conflict detected — auto-complaint created', [
            'ride_id'      => $ride->id,
            'booking_id'   => $booking->id,
            'driver_id'    => $driver->id,
            'passenger_id' => $passenger->id,
            'complaint_id' => $complaint->id,
        ]);

        return [
            'message'      => 'Both parties filed a no-show report. A support complaint has been opened automatically. No automatic penalty will be applied — the support team will investigate.',
            'conflict'     => true,
            'complaint_id' => $complaint->id,
        ];
    }

    // =========================================================================
    //  PRIVATE — PENALTY APPLICATION (Scenarios A and B, after 2h expiry)
    // =========================================================================

    /**
     * The 2-hour window expired with no counter-report.
     * The reporter wins. The target is penalised.
     */
    private function applyPenalty(NoshowReport $report): void
    {
        $booking   = Booking::with(['ride', 'user'])->findOrFail($report->booking_id);
        $ride      = $booking->ride;
        $passenger = User::findOrFail($report->target_role === 'passenger' ? $report->target_id : $report->reporter_id);
        $driver    = User::findOrFail($ride->driver_id);

        if ($report->target_role === 'passenger') {
            // ── Driver won → passenger is penalised ───────────────────────────
            $booking->update(['status' => 'no_show', 'completed_at' => now()]);

            // Score: −15 for cash; e-pay = 0 pts (wallet handles the punishment)
            $this->scoreService->recordPassengerNoShow($passenger, $booking, $report->payment_method);

            // Wallet: escrow → driver 95% + primary 5% (e-pay only)
            if ($report->payment_method === 'e-pay') {
                $this->walletService->processPassengerNoShow($booking, $ride, $passenger);
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
            } catch (\Throwable) {}

            // Notify driver: you received your earnings
            try {
                $this->notificationService->createNotification(
                    $driver,
                    'noshow_resolved_in_your_favor',
                    'تم البت في تقرير الغياب',
                    "تم تأكيد غياب الراكب وتطبيق العقوبة المقررة." .
                    ($report->payment_method === 'e-pay' ? ' تم تحويل المبلغ إلى محفظتك.' : ''),
                    ['booking_id' => $booking->id],
                    'normal',
                    'ride'
                );
            } catch (\Throwable) {}

        } else {
            // ── Passenger won → driver is penalised ───────────────────────────
            $booking->update(['status' => 'no_show', 'completed_at' => now()]);

            // Score: −15 for cash; e-pay = 0 pts (wallet handles the punishment)
            $this->scoreService->recordDriverNoShow($driver, $ride, $report->payment_method);

            // Wallet: escrow → passenger 100% refund (e-pay only)
            if ($report->payment_method === 'e-pay') {
                $this->walletService->processDriverNoShowRefund($ride, $booking, $passenger);
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
            } catch (\Throwable) {}

            // Notify passenger: refunded (e-pay) or resolved (cash)
            try {
                $this->notificationService->createNotification(
                    $passenger,
                    'noshow_resolved_in_your_favor',
                    'تم البت في تقرير الغياب',
                    "تم تأكيد غياب السائق وتطبيق العقوبة المقررة." .
                    ($report->payment_method === 'e-pay' ? ' تم استرجاع المبلغ إلى محفظتك.' : ''),
                    ['booking_id' => $booking->id],
                    'normal',
                    'ride'
                );
            } catch (\Throwable) {}
        }
    }

    // =========================================================================
    //  PRIVATE — COMPLAINT DESCRIPTION BUILDER
    // =========================================================================

    private function buildConflictDescription(
        User         $driver,
        User         $passenger,
        Booking      $booking,
        Ride         $ride,
        NoshowReport $firstReport,
        string       $secondReporterRole,
    ): string {
        $firstRole  = $firstReport->reporter_role === 'driver' ? 'السائق' : 'الراكب';
        $secondRole = $secondReporterRole === 'driver'          ? 'السائق' : 'الراكب';

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
            "  ثاني تقرير: {$secondRole} — " . now()->format('Y-m-d H:i:s') . " (هذا التقرير)",
            "  نافذة النزاع: {$firstReport->expires_at->format('Y-m-d H:i:s')}",
            '',
            '── المطلوب من فريق الدعم ──────────────────',
            '  1. التحقق من سجلات الموقع / وقت دخول التطبيق',
            '  2. التواصل مع الطرفين إن لزم',
            '  3. تطبيق العقوبة على الطرف المخطئ يدوياً',
        ]);
    }
}
