<?php

namespace App\Modules\Console\Services;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Modules\Communication\Contracts\NotificationPort;
use App\Modules\Money\Contracts\CancellationRefundInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * The staff cancellation flow, split out of StaffOperationsController.
 *
 * The controller keeps its routes (POST /api/staff/trips/{rideId}/cancel and
 * POST /api/staff/bookings/{bookingId}/cancel) and its method signatures; this class owns the
 * reason validation, the transactional money-first cancellation, the seat restore, the
 * passenger notifications and the audit logs. Bodies moved verbatim; it takes the request
 * payload array and the acting employee, never a Request, so the request_in_services budget
 * stays at exactly 1.
 */
final class StaffCancellationService
{
    public function __construct(
        private readonly CancellationRefundInterface $cancellationRefund,
        private readonly NotificationPort $notificationService,
    ) {}

    private function serverError(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'An unexpected error occurred. Please try again.',
        ], 500);
    }

    public function cancelTrip(int $rideId, array $input, $agent): JsonResponse
    {
        if ($reasonFailure = $this->cancellationReasonFailure($input)) {
            return $reasonFailure;
        }

        try {
            $ride = Ride::findOrFail($rideId);

            // Only cancel active/full rides
            if (! in_array($ride->status, ['active', 'full', 'awaiting_confirmation'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Cannot cancel a ride with status: {$ride->status}.",
                ], 422);
            }

            $refund = ['refunded' => 0.0, 'bookings' => 0, 'needs_review' => 0, 'amount_source' => 'none'];

            DB::transaction(function () use ($ride, $input, $agent, &$refund) {
                $refund = $this->cancelTripWithinTransaction($ride, $input, $agent);
            }, attempts: 3);

            return response()->json([
                'status' => 'success',
                'message' => 'Trip cancelled successfully. All affected passengers have been notified.',
                'data' => [
                    'ride_id' => $ride->id,
                    'new_status' => 'cancelled',
                    'bookings_cancelled' => $ride->bookings()->where('status', 'cancelled')->count(),
                    // Decision 6: surfaced so staff can see the money actually moved, and so a
                    // legacy booking with no snapshot is visible instead of silently dropped.
                    'refund_total' => $refund['refunded'],
                    'refund_bookings' => $refund['bookings'],
                    'refund_amount_source' => $refund['amount_source'],
                    'needs_manual_review' => $refund['needs_review'],
                ],
            ]);

        } catch (ModelNotFoundException) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ride not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Staff trip cancellation failed', [
                'ride_id' => $rideId,
                'error' => $e->getMessage(),
            ]);

            return $this->serverError();
        }
    }

    public function cancelBooking(int $bookingId, array $input, $agent): JsonResponse
    {
        if ($reasonFailure = $this->cancellationReasonFailure($input)) {
            return $reasonFailure;
        }

        try {
            $booking = Booking::with(['ride', 'user'])->findOrFail($bookingId);

            // Only cancel active bookings
            if (! in_array($booking->status, ['pending', 'confirmed'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Cannot cancel a booking with status: {$booking->status}.",
                ], 422);
            }

            $refund = ['refunded' => 0.0, 'bookings' => 0, 'needs_review' => 0, 'amount_source' => 'none'];

            DB::transaction(function () use ($booking, $input, $agent, &$refund) {
                $refund = $this->cancelBookingWithinTransaction($booking, $input, $agent);
            }, attempts: 3);

            return response()->json([
                'status' => 'success',
                'message' => 'Booking cancelled successfully. The passenger has been notified.',
                'data' => [
                    'booking_id' => $booking->id,
                    'new_status' => 'cancelled',
                    'seats_restored_to_ride' => $booking->seats,
                    'refund_total' => $refund['refunded'],
                    'refund_amount_source' => $refund['amount_source'],
                    'needs_manual_review' => $refund['needs_review'],
                ],
            ]);

        } catch (ModelNotFoundException) {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking not found.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Staff booking cancellation failed', [
                'booking_id' => $bookingId,
                'error' => $e->getMessage(),
            ]);

            return $this->serverError();
        }
    }

    /**
     * Shared reason validation for both staff cancellation endpoints.
     * Returns the 422 response to send back, or null when the input is valid.
     */
    private function cancellationReasonFailure(array $input): ?JsonResponse
    {
        $validator = Validator::make($input, [
            'reason' => 'required|string|min:10|max:500',
        ], [
            'reason.required' => 'A cancellation reason is required.',
            'reason.min' => 'Reason must be at least 10 characters.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        return null;
    }

    /**
     * Work performed inside the staff trip-cancellation transaction.
     *
     * @return array{refunded: float, bookings: int, needs_review: int, amount_source: string}
     */
    private function cancelTripWithinTransaction(Ride $ride, array $input, $agent): array
    {
        // The bookings this cancellation actually affects.
        $affectedBookings = $ride->bookings()
            ->whereIn('status', ['confirmed', 'pending'])
            ->get();

        // Decision 6 (owner 2026-10-02): a staff-initiated cancellation refunds every
        // affected passenger IN FULL and applies NO driver score penalty. Before this the
        // endpoint only flipped the status - the comment above claimed "refund all
        // confirmed bookings" but no money ever moved, so e-pay escrow stayed stranded in
        // SyCash (RV-03). The amount comes from the RV-40 `amount_paid` snapshot, never
        // from the mutable ride price.
        //
        // Money first: if the refund cannot be funded the transaction rolls back and the
        // ride is NOT cancelled, so we can never leave a cancelled ride with stranded money.
        $refund = $this->cancellationRefund
            ->refundPassengersForStaffCancellation($ride, $affectedBookings);

        // Cancel every affected booking.
        foreach ($affectedBookings as $booking) {
            $booking->update(['status' => 'cancelled']);
        }

        // Cancel the ride
        $ride->update(['status' => 'cancelled']);

        // Notify affected passengers
        $this->notifyPassengersOfCancelledTrip($affectedBookings, $ride, $input);

        $this->logCancelledTrip($ride, $agent, $input, $affectedBookings, $refund);

        return $refund;
    }

    /**
     * Notify every passenger whose booking was cancelled along with the ride.
     */
    private function notifyPassengersOfCancelledTrip($affectedBookings, Ride $ride, array $input): void
    {
        $notificationService = $this->notificationService;
        $reason = $input['reason'] ?? null;

        foreach ($affectedBookings as $booking) {
            $notificationService->createNotification(
                User::find($booking->user_id),
                'ride_cancelled',
                'رحلتك تم إلغاؤها',
                "تم إلغاء الرحلة من قِبل فريق الدعم. السبب: {$reason}",
                ['ride_id' => $ride->id],
                'high',
                'ride'
            );
        }
    }

    /**
     * Audit trail for a staff-initiated trip cancellation.
     */
    private function logCancelledTrip(Ride $ride, $agent, array $input, $affectedBookings, array $refund): void
    {
        Log::info('Staff cancelled trip', [
            'ride_id' => $ride->id,
            'agent_id' => $agent->id,
            'agent_name' => $agent->fullName(),
            'reason' => $input['reason'] ?? null,
            'bookings_affected' => $affectedBookings->count(),
            'refunded_total' => $refund['refunded'],
            'refunded_bookings' => $refund['bookings'],
            'needs_review' => $refund['needs_review'],
            'driver_score_penalty' => 'none (decision 6: not the driver\'s fault)',
        ]);
    }

    /**
     * Work performed inside the staff booking-cancellation transaction.
     *
     * @return array{refunded: float, bookings: int, needs_review: int, amount_source: string}
     */
    private function cancelBookingWithinTransaction(Booking $booking, array $input, $agent): array
    {
        $seatsToRestore = $booking->seats;
        $ride = $booking->ride;

        $refund = $this->refundCancelledBooking($booking, $ride);

        // Cancel booking
        $booking->update(['status' => 'cancelled']);

        $this->restoreSeatsForCancelledBooking($ride, $seatsToRestore);

        // Notify the passenger
        $this->notifyPassengerOfCancelledBooking($booking, $ride, $input);

        $this->logCancelledBooking($booking, $agent, $input, $seatsToRestore, $refund);

        return $refund;
    }

    /**
     * Refund the cancelled booking in full.
     *
     * Decision 6 (owner 2026-10-02): full refund, no driver score penalty. Same reason
     * as cancelTrip - this endpoint used to move no money at all, stranding e-pay
     * escrow. Refund BEFORE the status flip so a failed refund rolls the whole thing back.
     *
     * @return array{refunded: float, bookings: int, needs_review: int, amount_source: string}
     */
    private function refundCancelledBooking(Booking $booking, ?Ride $ride): array
    {
        if (! $ride) {
            return ['refunded' => 0.0, 'bookings' => 0, 'needs_review' => 0, 'amount_source' => 'none'];
        }

        // An ELOQUENT collection, not collect(): the service signature is typed to
        // Eloquent's Collection and a support-collection here was a hard 500.
        return $this->cancellationRefund
            ->refundPassengersForStaffCancellation($ride, Booking::whereIn('id', [$booking->id])->get());
    }

    /**
     * Give the seats back to the ride when it is still cancellable.
     */
    private function restoreSeatsForCancelledBooking($ride, $seatsToRestore): void
    {
        // Restore seats on the ride if it's still active
        if ($ride && in_array($ride->status, ['active', 'full', 'awaiting_confirmation'])) {
            $ride->increment('available_seats', $seatsToRestore);

            // If ride was full, set back to active
            if ($ride->status === 'full') {
                $ride->update(['status' => 'active']);
            }
        }
    }

    /**
     * Notify the passenger whose booking was cancelled.
     */
    private function notifyPassengerOfCancelledBooking(Booking $booking, ?Ride $ride, array $input): void
    {
        if (! $booking->user) {
            return;
        }

        $reason = $input['reason'] ?? null;

        $this->notificationService->createNotification(
            $booking->user,
            'booking_cancelled',
            'تم إلغاء حجزك',
            "تم إلغاء حجزك من قِبل فريق الدعم. السبب: {$reason}",
            ['booking_id' => $booking->id, 'ride_id' => $ride?->id],
            'high',
            'ride'
        );
    }

    /**
     * Audit trail for a staff-initiated booking cancellation.
     */
    private function logCancelledBooking(Booking $booking, $agent, array $input, $seatsToRestore, array $refund): void
    {
        Log::info('Staff cancelled booking', [
            'booking_id' => $booking->id,
            'ride_id' => $booking->ride_id,
            'agent_id' => $agent->id,
            'agent_name' => $agent->fullName(),
            'reason' => $input['reason'] ?? null,
            'seats' => $seatsToRestore,
            'refunded_total' => $refund['refunded'],
            'needs_review' => $refund['needs_review'],
            'driver_score_penalty' => 'none (decision 6: not the driver\'s fault)',
        ]);
    }
}
