<?php

namespace App\Docs;

/**
 * @OA\Get(
 *     path="/api/rides/search",
 *     operationId="ridesSearchGet",
 *     tags={"Rides"},
 *     summary="Search rides (GET)",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="from",  in="query", @OA\Schema(type="string")),
 *     @OA\Parameter(name="to",    in="query", @OA\Schema(type="string")),
 *     @OA\Parameter(name="date",  in="query", @OA\Schema(type="string", format="date")),
 *     @OA\Parameter(name="seats", in="query", @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Matching rides")
 * )
 *
 * @OA\Post(
 *     path="/api/rides/search",
 *     operationId="ridesSearchPost",
 *     tags={"Rides"},
 *     summary="Search rides (POST)",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(
 *
 *             @OA\Property(property="from",  type="string"),
 *             @OA\Property(property="to",    type="string"),
 *             @OA\Property(property="date",  type="string", format="date"),
 *             @OA\Property(property="seats", type="integer")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Matching rides")
 * )
 *
 * @OA\Post(
 *     path="/api/rides/route-options",
 *     operationId="ridesRouteOptions",
 *     tags={"Rides"},
 *     summary="Get route options before creating a ride",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"from","to"},
 *
 *             @OA\Property(property="from", type="string"),
 *             @OA\Property(property="to",   type="string")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Route options returned")
 * )
 *
 * @OA\Post(
 *     path="/api/rides/create-with-route",
 *     operationId="ridesCreateWithRoute",
 *     tags={"Rides"},
 *     summary="Create a ride with a pre-selected route",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(
 *             required={"from","to","date","seats","price"},
 *
 *             @OA\Property(property="from",  type="string"),
 *             @OA\Property(property="to",    type="string"),
 *             @OA\Property(property="date",  type="string", format="date-time"),
 *             @OA\Property(property="seats", type="integer"),
 *             @OA\Property(property="price", type="number"),
 *             @OA\Property(property="route", type="object",
 *                 description="Route object returned from route-options")
 *         )
 *     ),
 *
 *     @OA\Response(response=201, description="Ride created")
 * )
 *
 * @OA\Get(
 *     path="/api/rides",
 *     operationId="ridesList",
 *     tags={"Rides"},
 *     summary="List current user's rides",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Rides list"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *     path="/api/rides",
 *     operationId="ridesCreate",
 *     tags={"Rides"},
 *     summary="Create a new ride",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(
 *             required={"from","to","date","seats","price"},
 *
 *             @OA\Property(property="from",   type="string"),
 *             @OA\Property(property="to",     type="string"),
 *             @OA\Property(property="date",   type="string", format="date-time"),
 *             @OA\Property(property="seats",  type="integer"),
 *             @OA\Property(property="price",  type="number")
 *         )
 *     ),
 *
 *     @OA\Response(response=201, description="Ride created"),
 *     @OA\Response(response=422, description="Validation error")
 * )
 *
 * @OA\Get(
 *     path="/api/rides/{rideId}",
 *     operationId="ridesShow",
 *     tags={"Rides"},
 *     summary="Get ride details",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="rideId", in="path", required=true,
 *
 *         @OA\Schema(type="integer")
 *     ),
 *
 *     @OA\Response(response=200, description="Ride details"),
 *     @OA\Response(response=404, description="Not found")
 * )
 *
 * @OA\Patch(
 *     path="/api/rides/{rideId}/cancel",
 *     operationId="ridesCancel",
 *     tags={"Rides"},
 *     summary="Cancel a ride (driver)",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="rideId", in="path", required=true,
 *
 *         @OA\Schema(type="integer")
 *     ),
 *
 *     @OA\Response(response=200, description="Ride cancelled"),
 *     @OA\Response(response=403, description="Forbidden")
 * )
 *
 * @OA\Post(
 *     path="/api/rides/{rideId}/book",
 *     operationId="ridesBook",
 *     tags={"Rides"},
 *     summary="Book a ride (passenger)",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="rideId", in="path", required=true,
 *
 *         @OA\Schema(type="integer")
 *     ),
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"seats"},
 *
 *             @OA\Property(property="seats", type="integer")
 *         )
 *     ),
 *
 *     @OA\Response(response=201, description="Booking created")
 * )
 * un5 (owner ruling 2026-10-02): POST /api/rides/{rideId}/finish and
 * /api/rides/{rideId}/driver-confirm were REMOVED from the API - they only ever returned an info
 * message and changed nothing. There is no driver finish step: each passenger confirms their own
 * booking, their money moves to the driver, and the ride finishes automatically once every booking
 * is confirmed or terminal. Both OpenAPI entries are gone so no client is generated against them.
 *
 * @OA\Post(
 *     path="/api/rides/{rideId}/driver-no-show",
 *     operationId="ridesDriverNoShow",
 *     tags={"Rides"},
 *     summary="Report driver no-show (passenger)",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="rideId", in="path", required=true,
 *
 *         @OA\Schema(type="integer")
 *     ),
 *
 *     @OA\Response(response=200, description="Report submitted")
 * )
 */
class RideDocs {}
