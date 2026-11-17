<?php

namespace App\Modules\Ride\Services;

use App\Enums\ComplaintStatus;
use App\Enums\ComplaintType;
use App\Modules\Ride\Models\Booking;
use App\Modules\Ride\Models\NoshowReport;
use App\Modules\Ride\Models\Ride;
use App\Models\User;
use App\Modules\Communication\Contracts\NotificationPort;
use App\Modules\Console\Contracts\ComplaintRecorder;
use App\Support\Logging\SafeExceptionLog;
use Illuminate\Support\Facades\Log;

/**
 * Scenario C: both sides pressed no-show inside the dispute window. Both pending reports are
 * marked disputed, a no_show complaint is opened with the full context, both parties are told,
 * and no automatic penalty is applied.
 *
 * Split out of Noshowservice (behaviour unchanged). Bodies moved verbatim.
 */
final class NoShowConflictService
{
    public function __construct(
        private readonly NotificationPort $notificationService,
        private readonly ComplaintRecorder $complaints,
    ) {}

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

        $complaintId = $this->createConflictComplaint($driver, $passenger, $booking, $ride, $existingReport, $newReporterRole);

        $this->notifyConflictParties($driver, $passenger, $complaintId, $ride);

        Log::info('No-show conflict detected — auto-complaint created', [
            'ride_id' => $ride->id,
            'booking_id' => $booking->id,
            'driver_id' => $driver->id,
            'passenger_id' => $passenger->id,
            'complaint_id' => $complaintId,
        ]);

        return [
            'message' => 'Both parties filed a no-show report. A support complaint has been opened automatically. No automatic penalty will be applied — the support team will investigate.',
            'conflict' => true,
            'complaint_id' => $complaintId,
        ];
    }

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
    ): int {
        return $this->complaints->record([
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
        int $complaintId,
        Ride $ride
    ): void {
        try {
            $this->notificationService->createNotification(
                $passenger,
                'noshow_conflict',
                'تعارض تقارير الغياب',
                'كلا الطرفين ضغطا زر الغياب. تم فتح شكوى تلقائية وسيتدخل فريق الدعم للبت في الأمر. لن تُطبَّق أي عقوبة تلقائية.',
                ['ride_id' => $ride->id, 'complaint_id' => $complaintId],
                'high',
                'system'
            );
            $this->notificationService->createNotification(
                $driver,
                'noshow_conflict',
                'تعارض تقارير الغياب',
                'كلا الطرفين ضغطا زر الغياب. تم فتح شكوى تلقائية وسيتدخل فريق الدعم للبت في الأمر. لن تُطبَّق أي عقوبة تلقائية.',
                ['ride_id' => $ride->id, 'complaint_id' => $complaintId],
                'high',
                'system'
            );
        } catch (\Throwable $e) {
            Log::warning('no-show notification dispatch failed (non-fatal): '.SafeExceptionLog::message($e));
        }
    }

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
}
