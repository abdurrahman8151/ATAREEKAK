<?php

namespace App\Modules\Ride\Http\Handlers;

use App\DTOs\Ride\BookRideDTO;
use App\Http\Requests\BookRideRequest;
use App\Http\Resources\BookingResource;
use App\Modules\Ride\Services\BookingService;
use App\Support\Logging\SafeExceptionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Passenger booking action for RideController (bookRide), moved out verbatim.
 *
 * RideController keeps the public method name and signature, so the route binding is unchanged.
 * The body is the original one; the 201 success envelope, the 422 refusal, the generic 422 fault
 * and the log messages are kept exactly.
 */
class RideBookingHandler
{
    public function __construct(
        private readonly BookingService $bookingService,
    ) {}

    public function bookRide(BookRideRequest $request, int $rideId): JsonResponse
    {
        try {
            $dto = BookRideDTO::fromRequest($request->validated(), $request->user()->id, $rideId);
            $booking = $this->bookingService->bookRide($dto, $request->user());

            return response()->json([
                'success' => true,
                'data' => new BookingResource($booking),
                'message' => 'Ride booked successfully',
            ], 201);

        } catch (\InvalidArgumentException $e) {
            // Decision 11 (owner): a refusal must name its own gate. "The request could not be
            // completed" tells the caller nothing about WHICH rule stopped them.
            //
            // Every InvalidArgumentException thrown by the ride/booking domain is a curated,
            // user-facing sentence - see RideValidationService ("You must be verified as a passenger
            // to book rides"), BookingService ("Only pending bookings can be accepted"),
            // RideService, Noshowservice - so this type is precisely the "the user did something we
            // are refusing on purpose" signal, distinct from a fault.
            //
            // RV-13 STILL HOLDS: everything else, and above all QueryException with its SQL and
            // table names, falls through to the generic branch below and never reaches the client.
            Log::error('RideController: request refused', [
                'error' => SafeExceptionLog::message($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);

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
}
