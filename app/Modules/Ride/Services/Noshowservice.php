<?php

namespace App\Modules\Ride\Services;

use App\Modules\Ride\Models\Booking;
use App\Modules\Ride\Models\NoshowReport;
use App\Modules\Ride\Models\Ride;
use App\Models\User;
use App\Modules\Communication\Contracts\NotificationPort;
use App\Modules\Console\Contracts\ComplaintRecorder;
use App\Modules\Money\Contracts\NoShowSettlementInterface;
use App\Modules\Ride\Contracts\NoShowReportInterface;

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
final class Noshowservice implements NoShowReportInterface
{
    private readonly NoShowReportService $reports;

    private readonly NoShowConflictService $conflicts;

    private readonly NoShowPenaltyService $penalties;

    public function __construct(
        private readonly ScoreService $scoreService,
        private readonly NoShowSettlementInterface $noShowSettlement,
        private readonly NotificationPort $notificationService,
    ) {
        // Stage 2 C2: this class builds its collaborators by hand, so resolve the complaint recorder
        // the same way - keeping this constructor unchanged, and with it every call site.
        $this->conflicts = new NoShowConflictService($notificationService, app(ComplaintRecorder::class));
        $this->reports = new NoShowReportService($notificationService, $this->conflicts, new NoShowReportGuard);
        $this->penalties = new NoShowPenaltyService($scoreService, $noShowSettlement, $notificationService);
    }

    /** Delegates to NoShowReportService. */
    public function reportPassengerNoShow(int $bookingId, User $driver): array
    {
        return $this->reports->reportPassengerNoShow($bookingId, $driver);
    }

    /** Delegates to NoShowReportService. */
    public function reportDriverNoShow(int $rideId, User $passenger): array
    {
        return $this->reports->reportDriverNoShow($rideId, $passenger);
    }

    /** Delegates to NoShowPenaltyService. */
    public function resolveExpiredReports(): int
    {
        return $this->penalties->resolveExpiredReports();
    }
}
