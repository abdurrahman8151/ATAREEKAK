<?php

namespace App\Docs;

/**
 * ── Score ─────────────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/score",
 *     operationId="scoreShow",
 *     tags={"Profile"},
 *     summary="Get current user's score",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Score object"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Get(
 *     path="/api/score/history",
 *     operationId="scoreHistory",
 *     tags={"Profile"},
 *     summary="Get score history",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Score history list"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * ── Autocomplete ──────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/autocomplete",
 *     operationId="autocomplete",
 *     tags={"Rides"},
 *     summary="Location autocomplete suggestions",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="query", in="query", required=true,
 *
 *         @OA\Schema(type="string")
 *     ),
 *
 *     @OA\Response(response=200, description="Location suggestions"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * ── Profile ───────────────────────────────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/api/profile",
 *     operationId="profileUpdate",
 *     tags={"Profile"},
 *     summary="Update own profile",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\RequestBody(required=false,
 *
 *         @OA\MediaType(mediaType="multipart/form-data",
 *
 *             @OA\Schema(
 *
 *                 @OA\Property(property="name",   type="string"),
 *                 @OA\Property(property="phone",  type="string"),
 *                 @OA\Property(property="avatar", type="string", format="binary")
 *             )
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Profile updated"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *     path="/api/profile/documents",
 *     operationId="profileDocuments",
 *     tags={"Profile"},
 *     summary="Upload verification documents",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\MediaType(mediaType="multipart/form-data",
 *
 *             @OA\Schema(
 *
 *                 @OA\Property(property="document_type", type="string",
 *                     enum={"id_card","driver_license","vehicle_registration"}),
 *                 @OA\Property(property="front", type="string", format="binary"),
 *                 @OA\Property(property="back",  type="string", format="binary")
 *             )
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Documents uploaded"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * ── Verification ──────────────────────────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/api/profile/verify/passenger",
 *     operationId="verifyPassenger",
 *     tags={"Profile"},
 *     summary="Submit passenger verification request",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Verification submitted"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *     path="/api/profile/verify/driver",
 *     operationId="verifyDriver",
 *     tags={"Profile"},
 *     summary="Submit driver verification request",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Verification submitted"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Get(
 *     path="/api/profile/verify/status/{userId}",
 *     operationId="verifyStatus",
 *     tags={"Profile"},
 *     summary="Get verification status for a user",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="userId", in="path", required=true,
 *
 *         @OA\Schema(type="integer")
 *     ),
 *
 *     @OA\Response(response=200, description="Verification status"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * ── User Profile ──────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/profile/{userId}",
 *     operationId="profileShow",
 *     tags={"Profile"},
 *     summary="Get a user's public profile",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="userId", in="path", required=true,
 *
 *         @OA\Schema(type="integer")
 *     ),
 *
 *     @OA\Response(response=200, description="User profile"),
 *     @OA\Response(response=404, description="Not found")
 * )
 *
 * @OA\Post(
 *     path="/api/profile/{userId}/comments",
 *     operationId="profileComment",
 *     tags={"Profile"},
 *     summary="Post a comment on a user's profile",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="userId", in="path", required=true,
 *
 *         @OA\Schema(type="integer")
 *     ),
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"comment"},
 *
 *             @OA\Property(property="comment", type="string")
 *         )
 *     ),
 *
 *     @OA\Response(response=201, description="Comment posted"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *     path="/api/profile/{userId}/rate",
 *     operationId="profileRate",
 *     tags={"Profile"},
 *     summary="Rate a user",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="userId", in="path", required=true,
 *
 *         @OA\Schema(type="integer")
 *     ),
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"rating"},
 *
 *             @OA\Property(property="rating",  type="number", minimum=1, maximum=5),
 *             @OA\Property(property="comment", type="string")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Rating saved"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 */
class ProfileDocs {}
