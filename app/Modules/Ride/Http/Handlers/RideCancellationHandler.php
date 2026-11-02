<?php

namespace App\Modules\Ride\Http\Handlers;

use App\Http\Resources\BookingResource;
use App\Modules\Communication\Contracts\NotificationPort;
use App\Modules\Ride\Services\BookingService;
use App\Modules\Ride\Services\RideService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Cancellation actions for RideController, moved out verbatim.
 *
 * RideController keeps the public method names and signatures, so every route binding is
 * unchanged. The bodies below are the original ones: response envelopes, status codes and log
 * messages are kept exactly, including cancelRide's `status` envelope and cancelBooking's 422.
 */
class RideCancellationHandler
{
    public function __construct(
        private readonly RideService $rideService,
        private readonly BookingService $bookingService,
        private readonly NotificationPort $notificationService,
    ) {}

    public function cancelRide(Request $request, int $rideId): JsonResponse
    {
        try {
            $ride = $this->rideService->cancelRide($rideId, $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Ride cancelled successfully.',
                'ride' => $ride,
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Ride cancellation failed', [
                'ride_id' => $rideId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'error', 'message' => 'The request could not be completed. Please try again.'], 500);
        }
    }

    public function cancelBooking(int $bookingId, Request $request): JsonResponse
    {
        try {
            $booking = $this->bookingService->cancelBooking($bookingId, $request->user());

            try {
                $booking->loadMissing(['ride.driver']);
                if ($booking->ride?->driver) {
                    $this->notificationService->createNotification(
                        $booking->ride->driver,
                        'booking_cancelled_by_passenger',
                        'إلغاء حجز',
                        "ألغى أحد الركاب حجزه ({$booking->seats} مقعد). تم إعادة المقاعد لرحلتك.",
                        ['booking_id' => $booking->id, 'ride_id' => $booking->ride_id],
                        'normal',
                        'ride'
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('ride event notification failed (non-fatal): '.$e->getMessage());
            }

            return response()->json([
                'success' => true,
                'data' => new BookingResource($booking),
                'message' => 'Booking cancelled successfully',
            ]);

        } catch (\Throwable $e) {
            // RV-13: the exception text goes to the LOG, not to the client - a
            // QueryException carries the SQL and the table names.
            Log::error('RideController: request failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The request could not be completed. Please try again.',
            ], 422);
        }
    }
}
