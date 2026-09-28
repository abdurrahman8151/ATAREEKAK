<?php

namespace App\Docs;

/**
 * ── Staff Auth ────────────────────────────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/api/staff/login",
 *     operationId="staffLogin",
 *     tags={"Staff – Auth"},
 *     summary="Staff login",
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"email","password"},
 *
 *             @OA\Property(property="email",    type="string", format="email"),
 *             @OA\Property(property="password", type="string", format="password")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="JWT returned"),
 *     @OA\Response(response=401, description="Invalid credentials")
 * )
 *
 * @OA\Post(
 *     path="/api/staff/refresh",
 *     operationId="staffRefresh",
 *     tags={"Staff – Auth"},
 *     summary="Refresh staff JWT",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="New token")
 * )
 *
 * @OA\Post(
 *     path="/api/staff/logout",
 *     operationId="staffLogout",
 *     tags={"Staff – Auth"},
 *     summary="Staff logout",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Logged out")
 * )
 *
 * @OA\Get(
 *     path="/api/staff/me",
 *     operationId="staffMe",
 *     tags={"Staff – Auth"},
 *     summary="Get current staff member",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Staff user object")
 * )
 *
 * ── Staff Reviews ─────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/staff/reviews",
 *     operationId="staffReviews",
 *     tags={"Staff – Operations"},
 *     summary="List all user reviews / comments",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Reviews list")
 * )
 *
 * @OA\Delete(
 *     path="/api/staff/reviews/{commentId}",
 *     operationId="staffDeleteReview",
 *     tags={"Staff – Operations"},
 *     summary="Delete a review / comment",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="commentId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Deleted")
 * )
 *
 * ── Staff Users ───────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/staff/users",
 *     operationId="staffUsers",
 *     tags={"Staff – Operations"},
 *     summary="List all users",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Users list")
 * )
 *
 * @OA\Get(
 *     path="/api/staff/users/{userId}",
 *     operationId="staffUserProfile",
 *     tags={"Staff – Operations"},
 *     summary="Get a user's profile",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="User profile")
 * )
 *
 * ── Staff Trips & Bookings ────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/staff/trips",
 *     operationId="staffTrips",
 *     tags={"Staff – Operations"},
 *     summary="List all trips",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Trips list")
 * )
 *
 * @OA\Get(
 *     path="/api/staff/bookings",
 *     operationId="staffBookings",
 *     tags={"Staff – Operations"},
 *     summary="List all bookings",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Bookings list")
 * )
 *
 * @OA\Post(
 *     path="/api/staff/trips/{rideId}/cancel",
 *     operationId="staffCancelTrip",
 *     tags={"Staff – Operations"},
 *     summary="Cancel a trip (staff action)",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="rideId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\RequestBody(required=false,
 *
 *         @OA\JsonContent(@OA\Property(property="reason", type="string"))
 *     ),
 *
 *     @OA\Response(response=200, description="Trip cancelled")
 * )
 *
 * @OA\Post(
 *     path="/api/staff/bookings/{bookingId}/cancel",
 *     operationId="staffCancelBooking",
 *     tags={"Staff – Operations"},
 *     summary="Cancel a booking (staff action)",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="bookingId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\RequestBody(required=false,
 *
 *         @OA\JsonContent(@OA\Property(property="reason", type="string"))
 *     ),
 *
 *     @OA\Response(response=200, description="Booking cancelled")
 * )
 *
 * ── Staff Complaints ──────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/staff/complaints",
 *     operationId="staffComplaints",
 *     tags={"Staff – Complaints"},
 *     summary="List all complaints",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Complaints list")
 * )
 *
 * @OA\Get(
 *     path="/api/staff/complaints/{id}",
 *     operationId="staffComplaintShow",
 *     tags={"Staff – Complaints"},
 *     summary="Get a complaint",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Complaint object")
 * )
 *
 * @OA\Patch(
 *     path="/api/staff/complaints/{id}/respond",
 *     operationId="staffComplaintRespond",
 *     tags={"Staff – Complaints"},
 *     summary="Respond to a complaint",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"response"},
 *
 *             @OA\Property(property="response", type="string")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Response saved")
 * )
 *
 * @OA\Patch(
 *     path="/api/staff/complaints/{id}/escalate",
 *     operationId="staffComplaintEscalate",
 *     tags={"Staff – Complaints"},
 *     summary="Escalate a complaint to admin",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\RequestBody(required=false,
 *
 *         @OA\JsonContent(@OA\Property(property="reason", type="string"))
 *     ),
 *
 *     @OA\Response(response=200, description="Escalated")
 * )
 *
 * ── Staff Verifications (admin + system_admin) ────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/staff/verifications/pending",
 *     operationId="staffVerifPending",
 *     tags={"Staff – Complaints"},
 *     summary="[admin] List pending verifications",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Pending verifications")
 * )
 *
 * @OA\Post(
 *     path="/api/staff/verifications/{userId}/approve",
 *     operationId="staffVerifApprove",
 *     tags={"Staff – Complaints"},
 *     summary="[admin] Approve a verification",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Approved")
 * )
 *
 * @OA\Post(
 *     path="/api/staff/verifications/{userId}/reject",
 *     operationId="staffVerifReject",
 *     tags={"Staff – Complaints"},
 *     summary="[admin] Reject a verification",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\RequestBody(required=false,
 *
 *         @OA\JsonContent(@OA\Property(property="reason", type="string"))
 *     ),
 *
 *     @OA\Response(response=200, description="Rejected")
 * )
 *
 * ── Escalated Complaints (admin + system_admin) ───────────────────────────────
 *
 * @OA\Get(
 *     path="/api/staff/escalated-complaints",
 *     operationId="staffEscalatedComplaints",
 *     tags={"Staff – Complaints"},
 *     summary="[admin] List escalated complaints",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Escalated complaints list")
 * )
 *
 * @OA\Patch(
 *     path="/api/staff/escalated-complaints/{id}/resolve",
 *     operationId="staffEscalatedResolve",
 *     tags={"Staff – Complaints"},
 *     summary="[admin] Resolve an escalated complaint",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"resolution"},
 *
 *             @OA\Property(property="resolution", type="string")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Resolved")
 * )
 */
class StaffDocs {}
