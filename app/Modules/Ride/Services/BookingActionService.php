<?php

namespace App\Modules\Ride\Services;

use App\Models\User;
use App\Modules\Communication\Contracts\NotificationPort;
use App\Modules\Ride\Models\Booking;
use App\Support\Logging\SafeExceptionLog;
use Illuminate\Support\Facades\Log;

/**
 * Passenger-facing booking actions and the notification that follows each one.
 *
 * The HTTP layer validates the payload and hands this class a validated array (or plain
 * scalars and the acting user), never the Request, so the same action can be driven from a
 * console command or a job. Every notification is deliberately non-fatal: the state and
 * money change has already been committed and must not be reported as a failure because a
 * notification write failed.
 */
final class BookingActionService
{
    public function __construct(
        private readonly BookingService $bookingService,
        private readonly NotificationPort $notificationService,
    ) {}

    /**
     * A passenger confirms the ride took place.
     *
     * @return array{message: string, ride_finished: bool}
     */
    public function confirmPassengerCompletion(int $bookingId, User $passenger): array
    {
        $result = $this->bookingService->passengerConfirmCompletion($bookingId, $passenger);

        $this->notifyDriverOfConfirmation($bookingId, $passenger, $result['ride_finished']);

        return $result;
    }

    /**
     * A passenger cancels a subset of the seats on their booking.
     *
     * @param  array<string, mixed>  $validated  the validated request payload
     * @return array{message: string, data: array<string, mixed>}
     */
    public function cancelPartialSeats(int $bookingId, array $validated, User $passenger): array
    {
        $result = $this->bookingService->cancelPartialSeats(
            $bookingId,
            $validated['seats_to_cancel'],
            $passenger
        );

        $this->notifyDriverOfPartialCancellation($bookingId, $validated['seats_to_cancel']);

        return $result;
    }

    /**
     * A driver reports that a confirmed passenger did not show up.
     *
     * @return array{message: string}
     */
    public function reportPassengerNoShow(int $bookingId, User $driver): array
    {
        $result = $this->bookingService->reportPassengerNoShow($bookingId, $driver);

        $this->notifyPassengerOfNoShow($bookingId);

        return $result;
    }

    /**
     * Always tell the driver that this passenger confirmed (and was paid). If the ride is
     * now fully finished, send the separate "all done" notice instead.
     */
    private function notifyDriverOfConfirmation(int $bookingId, User $passenger, bool $rideFinished): void
    {
        try {
            $booking = Booking::with('ride.driver')->find($bookingId);

            if (! $booking?->ride?->driver) {
                return;
            }

            if ($rideFinished) {
                // All passengers confirmed - ride is closed
                $this->notificationService->createNotification(
                    $booking->ride->driver,
                    'ride_completed',
                    'اكتملت رحلتك',
                    'أكد جميع الركاب وصولهم. تم تحويل جميع المبالغ إلى محفظتك.',
                    ['ride_id' => $booking->ride_id],
                    'high',
                    'ride'
                );

                return;
            }

            // Partial - this passenger confirmed, others still pending
            $passengerName = trim(
                ($passenger->first_name ?? '').' '.
                ($passenger->last_name ?? '')
            );

            $this->notificationService->createNotification(
                $booking->ride->driver,
                'passenger_confirmed',
                'تأكيد ركاب',
                "أكد {$passengerName} وصوله. تم تحويل حصته إلى محفظتك.",
                ['booking_id' => $bookingId, 'ride_id' => $booking->ride_id],
                'normal',
                'ride'
            );
        } catch (\Throwable $e) {
            // T3-13: non-fatal by intent, but it must be visible.
            Log::warning('ride confirmation notification failed (non-fatal): '.SafeExceptionLog::message($e));
        }
    }

    private function notifyDriverOfPartialCancellation(int $bookingId, int $seatsToCancel): void
    {
        try {
            $booking = Booking::with('ride.driver')->find($bookingId);

            if ($booking?->ride?->driver) {
                $this->notificationService->createNotification(
                    $booking->ride->driver,
                    'seats_partially_cancelled',
                    'إلغاء جزئي للمقاعد',
                    "ألغى أحد الركاب {$seatsToCancel} مقعد من حجزه.",
                    ['booking_id' => $bookingId, 'ride_id' => $booking->ride_id],
                    'normal',
                    'ride'
                );
            }
        } catch (\Throwable $e) {
            Log::warning('ride event notification failed (non-fatal): '.SafeExceptionLog::message($e));
        }
    }

    private function notifyPassengerOfNoShow(int $bookingId): void
    {
        try {
            $booking = Booking::with('user')->find($bookingId);

            if ($booking?->user) {
                $this->notificationService->createNotification(
                    $booking->user,
                    'no_show_recorded',
                    'تسجيل غياب',
                    'أفاد السائق بغيابك في موعد انطلاق الرحلة. تم تسجيل ذلك في ملفك.',
                    ['booking_id' => $bookingId],
                    'high',
                    'ride'
                );
            }
        } catch (\Throwable $e) {
            Log::warning('ride event notification failed (non-fatal): '.SafeExceptionLog::message($e));
        }
    }
}
