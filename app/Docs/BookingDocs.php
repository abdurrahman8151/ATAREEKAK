<?php

namespace App\Docs;

/**
 * @OA\Get(
 *     path="/api/bookings",
 *     operationId="bookingsList",
 *     tags={"Bookings"},
 *     summary="List current user's bookings",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Bookings list"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *     path="/api/bookings/{bookingId}/accept",
 *     operationId="bookingsAccept",
 *     tags={"Bookings"},
 *     summary="Accept a booking request (driver)",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="bookingId", in="path", required=true,
 *
 *         @OA\Schema(type="integer")
 *     ),
 *
 *     @OA\Response(response=200, description="Booking accepted")
 * )
 *
 * @OA\Post(
 *     path="/api/bookings/{bookingId}/reject",
 *     operationId="bookingsReject",
 *     tags={"Bookings"},
 *     summary="Reject a booking request (driver)",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="bookingId", in="path", required=true,
 *
 *         @OA\Schema(type="integer")
 *     ),
 *
 *     @OA\Response(response=200, description="Booking rejected")
 * )
 *
 * @OA\Post(
 *     path="/api/bookings/{bookingId}/cancel",
 *     operationId="bookingsCancel",
 *     tags={"Bookings"},
 *     summary="Cancel a booking (passenger)",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="bookingId", in="path", required=true,
 *
 *         @OA\Schema(type="integer")
 *     ),
 *
 *     @OA\Response(response=200, description="Booking cancelled")
 * )
 *
 * @OA\Post(
 *     path="/api/bookings/{bookingId}/cancel-seats",
 *     operationId="bookingsCancelSeats",
 *     tags={"Bookings"},
 *     summary="Cancel partial seats in a booking",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="bookingId", in="path", required=true,
 *
 *         @OA\Schema(type="integer")
 *     ),
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"seats"},
 *
 *             @OA\Property(property="seats", type="integer", minimum=1)
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Seats cancelled")
 * )
 *
 * @OA\Post(
 *     path="/api/bookings/{bookingId}/passenger-confirm",
 *     operationId="bookingsPassengerConfirm",
 *     tags={"Bookings"},
 *     summary="Passenger confirms ride completion",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="bookingId", in="path", required=true,
 *
 *         @OA\Schema(type="integer")
 *     ),
 *
 *     @OA\Response(response=200, description="Confirmed")
 * )
 *
 * @OA\Post(
 *     path="/api/bookings/{bookingId}/passenger-no-show",
 *     operationId="bookingsPassengerNoShow",
 *     tags={"Bookings"},
 *     summary="Report passenger no-show (driver)",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="bookingId", in="path", required=true,
 *
 *         @OA\Schema(type="integer")
 *     ),
 *
 *     @OA\Response(response=200, description="Report submitted")
 * )
 */
class BookingDocs {}
