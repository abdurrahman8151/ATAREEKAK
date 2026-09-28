<?php

namespace App\Docs;

/**
 * @OA\Get(
 *     path="/api/notifications",
 *     operationId="notifList",
 *     tags={"Notifications"},
 *     summary="List notifications",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Notification list"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Get(
 *     path="/api/notifications/unread-count",
 *     operationId="notifUnreadCount",
 *     tags={"Notifications"},
 *     summary="Get unread notification count",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Unread count")
 * )
 *
 * @OA\Get(
 *     path="/api/notifications/categories",
 *     operationId="notifCategories",
 *     tags={"Notifications"},
 *     summary="Get notification categories",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Categories list")
 * )
 *
 * @OA\Post(
 *     path="/api/notifications/read-all",
 *     operationId="notifReadAll",
 *     tags={"Notifications"},
 *     summary="Mark all notifications as read",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="All marked as read")
 * )
 *
 * @OA\Post(
 *     path="/api/notifications/bulk-action",
 *     operationId="notifBulkAction",
 *     tags={"Notifications"},
 *     summary="Perform bulk action on notifications",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"action","ids"},
 *
 *             @OA\Property(property="action", type="string", enum={"read","unread","delete"}),
 *             @OA\Property(property="ids", type="array", @OA\Items(type="integer"))
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Action performed")
 * )
 *
 * @OA\Post(
 *     path="/api/notifications/{id}/read",
 *     operationId="notifRead",
 *     tags={"Notifications"},
 *     summary="Mark a notification as read",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Marked as read")
 * )
 *
 * @OA\Post(
 *     path="/api/notifications/{id}/unread",
 *     operationId="notifUnread",
 *     tags={"Notifications"},
 *     summary="Mark a notification as unread",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Marked as unread")
 * )
 *
 * @OA\Delete(
 *     path="/api/notifications/{id}",
 *     operationId="notifDelete",
 *     tags={"Notifications"},
 *     summary="Delete a notification",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Deleted")
 * )
 */
class NotificationDocs {}
