<?php

namespace App\Modules\Ride\Http\Controllers;

use App\Exceptions\Domain\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookRideRequest;
use App\Http\Requests\CreateRideRequest;
use App\Http\Resources\RideResource;
use App\Modules\Ride\Http\Handlers\RideBookingHandler;
use App\Modules\Ride\Http\Handlers\RideBookingResponseHandler;
use App\Modules\Ride\Http\Handlers\RideCancellationHandler;
use App\Modules\Ride\Http\Handlers\RideLifecycleHandler;
use App\Modules\Ride\Http\Handlers\RideSearchReadHandler;
use App\Modules\Ride\Services\BookingActionService;
use App\Modules\Ride\Services\GeocodingService;
use App\Modules\Ride\Services\RideService;
use App\Modules\Ride\Services\RouteCalculationService;
use App\Support\Logging\SafeExceptionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ride Controller
 *
 * FIXED:
 *  1. create() — broken catch block referenced $ride outside its scope
 *                and returned 201 on exception. Rewritten cleanly.
 *  2. passengerConfirmCompletion() — catch (\Exception) misses ArgumentCountError
 *     and other Error subclasses; changed to catch (\Throwable).
 *  3. All other catch blocks also upgraded to \Throwable for the same reason.
 *
 * ── Caching summary ──────────────────────────────────────────────────────────
 *
 *  CACHED  getRouteOptions()  route.options.{lat1}.{lng1}.{lat2}.{lng2}  30 min
 *  CACHED  autocomplete()     geocode.autocomplete.{text}                 1 hour
 *
 *  Everything else is either a mutation or a per-user live read (seat counts,
 *  booking status, available seats) where staleness would directly harm UX.
 */
class RideController extends Controller
{
    public function __construct(
        private readonly RideService $rideService,
        private readonly GeocodingService $geocodingService,
        private readonly RouteCalculationService $routeService,
        private readonly RideCancellationHandler $cancellation,
        private readonly RideBookingResponseHandler $bookingResponses,
        private readonly RideLifecycleHandler $lifecycle,
        private readonly RideSearchReadHandler $searchRead,
        private readonly RideBookingHandler $booking,
        private readonly BookingActionService $bookingActions,
    ) {}

    // =========================================================================
    // CREATE RIDE
    // =========================================================================

    /**
     * POST /rides
     *
     * FIXED: catch block previously referenced $ride which only existed inside
     * try scope, and returned 201 even on failure. Now uses a single clean
     * try/catch with \Throwable to also catch PHP Errors.
     */
    public function create(CreateRideRequest $request): JsonResponse
    {
        return $this->lifecycle->create($request);
    }

    // =========================================================================
    // ROUTE OPTIONS
    // =========================================================================

    /**
     * GET /rides/route-options
     *
     * CACHED — 30 minutes per coordinate pair.
     */
    public function getRouteOptions(Request $request): JsonResponse
    {
        return $this->searchRead->getRouteOptions($request);
    }

    // =========================================================================
    // BOOK RIDE
    // =========================================================================

    /**
     * POST /rides/{rideId}/book
     */
    public function bookRide(BookRideRequest $request, int $rideId): JsonResponse
    {
        return $this->booking->bookRide($request, $rideId);
    }

    // =========================================================================
    // SHOW
    // =========================================================================

    /**
     * GET /rides/{rideId}
     *
     * NOT cached — total_booked_seats changes with every booking.
     */
    // =========================================================================
    // SHOW
    // =========================================================================

    /**
     * GET /rides/{rideId}
     *
     * NOT cached — seat counts and booking list change with every booking.
     * Returns the full ride data PLUS all bookings with passenger details.
     */
    // =========================================================================
    // SHOW — PUBLIC (passenger browsing a ride)
    // =========================================================================

    /**
     * GET /rides/{rideId}
     *
     * Public endpoint. Returns ride details + seat counts ONLY.
     * NO passenger names, NO communication numbers, NO booking list.
     * Any authenticated user may call this.
     */
    public function show(int $rideId): JsonResponse
    {
        return $this->searchRead->show($rideId);
    }

    // =========================================================================
    // DRIVER VIEW — PRIVATE (only the ride's own driver)
    // =========================================================================

    /**
     * GET /rides/{rideId}/driver-view
     *
     * Returns the full booking list with passenger details and communication
     * numbers. Returns 403 for any user who is NOT the driver of this ride.
     */
    public function driverView(int $rideId, Request $request): JsonResponse
    {
        return $this->searchRead->driverView($rideId, $request);
    }

    // =========================================================================
    // DRIVER RIDE LIST
    // =========================================================================

    public function getRides(Request $request): JsonResponse
    {
        return $this->lifecycle->getRides($request);
    }

    // =========================================================================
    // SEARCH
    // =========================================================================

    public function searchRides(Request $request): JsonResponse
    {
        return $this->searchRead->searchRides($request);
    }

    // =========================================================================
    // AUTOCOMPLETE
    // =========================================================================

    /**
     * GET /rides/autocomplete
     *
     * CACHED — 1 hour per search text.
     */
    public function autocomplete(Request $request): JsonResponse
    {
        return $this->searchRead->autocomplete($request);
    }

    // =========================================================================
    // MY RIDES / MY BOOKINGS
    // =========================================================================

    public function index(Request $request): JsonResponse
    {
        return $this->lifecycle->index($request);
    }

    // AF-4: the old `search()` controller method was DELETED. It was a fourth
    // copy of the search feature (validation + rideService->searchRides +
    // loadCount + RideResource) with NO route wired to it — routes/api.php maps
    // /rides/search to searchRides() instead. Shipping an unrouted duplicate of
    // a hot path is exactly the "one concept, N places" defect the architecture
    // audit flagged, and it invites future callers to guess wrong.

    public function myBookings(Request $request): JsonResponse
    {
        return $this->lifecycle->myBookings($request);
    }

    // =========================================================================
    // CANCEL
    // =========================================================================

    public function cancelRide(Request $request, int $rideId): JsonResponse
    {
        return $this->cancellation->cancelRide($request, $rideId);
    }

    public function cancelBooking(int $bookingId, Request $request): JsonResponse
    {
        return $this->cancellation->cancelBooking($bookingId, $request);
    }

    // =========================================================================
    // ACCEPT / REJECT BOOKING
    // =========================================================================

    public function acceptBooking(int $bookingId, Request $request): JsonResponse
    {
        return $this->bookingResponses->acceptBooking($bookingId, $request);
    }

    public function rejectBooking(int $bookingId, Request $request): JsonResponse
    {
        return $this->bookingResponses->rejectBooking($bookingId, $request);
    }

    // =========================================================================
    // CREATE WITH ROUTE
    // =========================================================================

    public function createRideWithRoute(CreateRideRequest $request): JsonResponse
    {
        return $this->lifecycle->createRideWithRoute($request);
    }

    // =========================================================================
    // FINISH / CONFIRM - REMOVED (un5, owner ruling 2026-10-02, R2 sec 40.1)
    // =========================================================================
    //
    // `finishRide()` and `driverConfirmCompletion()` are gone from the API. They never did anything:
    // both returned a static info message and changed no state, which made it look as though a
    // driver had to call them. The real flow has no driver finish step - each passenger confirms
    // their own booking, their money moves to the driver, and the ride finishes automatically when
    // every booking is confirmed or terminal (BookingService::passengerConfirmCompletion).
    //
    // The RideService methods of the same names still exist and still do real work; they are used by
    // the seeders (empty-ride cash-fee refund, checkAndCompleteRide). Only the HTTP surface is gone.

    // =========================================================================
    // PASSENGER CONFIRM COMPLETION
    // =========================================================================

    /**
     * POST /bookings/{bookingId}/passenger-confirm
     *
     * FIXED: catch (\Exception) → catch (\Throwable)
     *
     * The original catch(\Exception) silently swallowed ArgumentCountError
     * thrown by PaymentStrategyFactory (an Error subclass, NOT Exception),
     * causing Laravel to return an HTML 500 page instead of JSON. The escrow
     * was already committed, leaving admin holding funds with no driver payout.
     *
     * Fix here + PaymentStrategyFactory fix together make this atomic.
     */
    public function passengerConfirmCompletion(Request $request, int $booking): JsonResponse
    {
        try {
            $result = $this->bookingActions->confirmPassengerCompletion($booking, $request->user());

            return response()->json([
                'status' => 'success',
                'message' => $result['message'],
                'ride_finished' => $result['ride_finished'],
            ]);

        } catch (DomainException|\InvalidArgumentException $e) {
            // A refusal is a CLIENT error, not a server fault. BookingService raises
            // InvalidArgumentException for every "you cannot confirm this" case and
            // DomainException for the typed subclasses. Both are mapped by RV-13's
            // shared error model in App\Exceptions\Handler (422 / 403 / 409, with the
            // `errors` bag preserved). Genuine internal faults (payment release
            // failures, DB errors) fall through to the catch-all below.
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Passenger confirmation failed', [
                'booking_id' => $booking,
                'user_id' => $request->user()->id,
                'error' => SafeExceptionLog::message($e),
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'The request could not be completed. Please try again.',
            ], 500);
        }
    }

    // =========================================================================
    // PARTIAL SEAT CANCEL
    // =========================================================================

    public function cancelPartialSeats(Request $request, int $bookingId): JsonResponse
    {
        $validated = $request->validate([
            'seats_to_cancel' => 'required|integer|min:1',
        ]);

        try {
            $result = $this->bookingActions->cancelPartialSeats(
                $bookingId,
                $validated,
                $request->user()
            );

            return response()->json([
                'status' => 'success',
                'message' => $result['message'],
                'data' => $result['data'],
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Partial seat cancellation failed', [
                'booking_id' => $bookingId,
                'user_id' => $request->user()->id,
                'error' => SafeExceptionLog::message($e),
                'class' => get_class($e),
            ]);

            return response()->json(['status' => 'error', 'message' => 'The request could not be completed. Please try again.'], 400);
        }
    }

    // =========================================================================
    // NO SHOW REPORTS
    // =========================================================================

    public function reportPassengerNoShow(Request $request, int $bookingId): JsonResponse
    {
        try {
            $result = $this->bookingActions->reportPassengerNoShow($bookingId, $request->user());

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

    public function reportDriverNoShow(Request $request, int $rideId): JsonResponse
    {
        return $this->bookingResponses->reportDriverNoShow($request, $rideId);
    }
}
