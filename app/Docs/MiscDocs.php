<?php

namespace App\Docs;

/**
 * @OA\Get(
 *     path="/api/complaints",
 *     operationId="complaintsList",
 *     tags={"Complaints"},
 *     summary="List own complaints",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Complaints list"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *     path="/api/complaints",
 *     operationId="complaintsStore",
 *     tags={"Complaints"},
 *     summary="Submit a complaint",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(
 *             required={"subject","body"},
 *
 *             @OA\Property(property="subject",       type="string"),
 *             @OA\Property(property="body",          type="string"),
 *             @OA\Property(property="ride_id",       type="integer", nullable=true),
 *             @OA\Property(property="complained_id", type="integer", nullable=true)
 *         )
 *     ),
 *
 *     @OA\Response(response=201, description="Complaint created")
 * )
 *
 * @OA\Get(
 *     path="/api/complaints/{id}",
 *     operationId="complaintsShow",
 *     tags={"Complaints"},
 *     summary="Get a specific complaint",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Complaint object"),
 *     @OA\Response(response=404, description="Not found")
 * )
 *
 * @OA\Post(
 *     path="/api/contact",
 *     operationId="contactStore",
 *     tags={"Complaints"},
 *     summary="Send a contact / support message",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"name","email","message"},
 *
 *             @OA\Property(property="name",    type="string"),
 *             @OA\Property(property="email",   type="string", format="email"),
 *             @OA\Property(property="message", type="string")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Message sent")
 * )
 */
class MiscDocs {}
