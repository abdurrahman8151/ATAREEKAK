<?php

namespace App\Services\Ride;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\NoshowReport;
use App\Models\Ride;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Payment\NoShowSettlement;
use App\Services\Score\ScoreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The scheduler side: pending reports that outlived their dispute window are resolved, and the
 * party that did not show up is penalised (score, and the money path for e-pay rides).
 *
 * Split out of Noshowservice (behaviour unchanged). Bodies moved verbatim.
 */
final class NoShowPenaltyService
{
    public function __construct(
        private readonly ScoreService $scoreService,
        private readonly NoShowSettlement $noShowSettlement,
        private readonly NotificationService $notificationService,
    ) {}

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
