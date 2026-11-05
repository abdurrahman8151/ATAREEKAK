<?php

namespace App\Modules\Ride\Http\Handlers;

use App\Http\Resources\BookingResource;
use App\Modules\Communication\Contracts\NotificationPort;
use App\Modules\Ride\Services\BookingService;
use App\Modules\Ride\Services\RideService;
use App\Support\Logging\SafeExceptionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Booking-response actions for RideController (accept, reject, passenger confirm, partial cancel,
 * and no-show reports), moved out verbatim.
 *
 * RideController keeps the public method names and signatures, so every route binding is
 * unchanged. The bodies are the original ones: response envelopes, status codes and log messages
 * are kept exactly.
 */
class RideBookingResponseHandler
{
    public function __construct(
        private readonly RideService $rideService,
        private readonly BookingService $bookingService,
        private readonly NotificationPort $notificationService,
    ) {}

    public function acceptBooking(int $bookingId, Request $request): JsonResponse
    {
        try {
            $booking = $this->bookingService->acceptBooking($bookingId, $request->user());

            try {
                $booking->loadMissing('user');
                if ($booking->user) {
                    $this->notificationService->createNotification(
                        $booking->user,
                        'booking_accepted',
                        'تم قبول حجزك',
                        'قبل السائق طلب حجزك. تحقق من تفاصيل رحلتك.',
                        ['booking_id' => $booking->id, 'ride_id' => $booking->ride_id],
                        'high',
                        'ride'
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('ride event notification failed (non-fatal): '.SafeExceptionLog::message($e));
            }

            return response()->json([
                'success' => true,
                'data' => new BookingResource($booking),
                'message' => 'Booking accepted successfully',
            ]);

        } catch (\Throwable $e) {
            // RV-13: the exception text goes to the LOG, not to the client - a
            // QueryException carries the SQL and the table names.
            Log::error('RideController: request failed', [
                'error' => SafeExceptionLog::message($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The request could not be completed. Please try again.',
            ], 422);
        }
    }

    public function rejectBooking(int $bookingId, Request $request): JsonResponse
    {
        try {
            $booking = $this->bookingService->rejectBooking($bookingId, $request->user());

            try {
                $booking->loadMissing('user');
                if ($booking->user) {
                    $this->notificationService->createNotification(
                        $booking->user,
                        'booking_rejected',
                        'تم رفض طلب حجزك',
                        'اعتذر السائق عن قبول طلبك. يمكنك البحث عن رحلة أخرى.',
                        ['booking_id' => $booking->id, 'ride_id' => $booking->ride_id],
                        'normal',
                        'ride'
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('ride event notification failed (non-fatal): '.SafeExceptionLog::message($e));
            }

            return response()->json([
                'success' => true,
                'data' => new BookingResource($booking),
                'message' => 'Booking rejected successfully',
            ]);

        } catch (\Throwable $e) {
            // RV-13: the exception text goes to the LOG, not to the client - a
            // QueryException carries the SQL and the table names.
            Log::error('RideController: request failed', [
                'error' => SafeExceptionLog::message($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The request could not be completed. Please try again.',
            ], 422);
        }
    }

    public function reportDriverNoShow(Request $request, int $rideId): JsonResponse
    {
        try {
            $result = $this->rideService->reportDriverNoShow($rideId, $request->user());

            try {
                $ride = $this->rideService->getRideById($rideId);
                if ($ride->driver) {
                    $this->notificationService->createNotification(
                        $ride->driver,
                        'driver_no_show_recorded',
                        'تقرير غياب',
                        'أفاد أحد الركاب بغيابك في موعد انطلاق الرحلة.',
                        ['ride_id' => $rideId],
                        'high',
                        'ride'
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('ride event notification failed (non-fatal): '.SafeExceptionLog::message($e));
            }

            return response()->json(['status' => 'success', 'message' => $result['message']]);

        } catch (\Throwable $e) {
            // RV-13: the exception text goes to the LOG, not to the client - a
            // QueryException carries the SQL and the table names.
            Log::error('RideController: request failed', [
                'error' => SafeExceptionLog::message($e),
            ]);

            return response()->json(['status' => 'error', 'message' => 'The request could not be completed. Please try again.'], 422);
        }
    }
}
