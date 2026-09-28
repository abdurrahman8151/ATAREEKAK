<?php

namespace App\Docs;

/**
 * @OA\Get(
 *     path="/api/chat/conversations",
 *     operationId="chatConversations",
 *     tags={"Chat"},
 *     summary="List conversations",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Conversation list"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *     path="/api/chat/conversations",
 *     operationId="chatStartConversation",
 *     tags={"Chat"},
 *     summary="Start or retrieve a conversation",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"user_id"},
 *
 *             @OA\Property(property="user_id", type="integer"),
 *             @OA\Property(property="ride_id", type="integer", nullable=true)
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Conversation object")
 * )
 *
 * @OA\Get(
 *     path="/api/chat/conversations/{conversationId}/messages",
 *     operationId="chatMessages",
 *     tags={"Chat"},
 *     summary="List messages in a conversation",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="conversationId", in="path", required=true,
 *
 *         @OA\Schema(type="integer")
 *     ),
 *
 *     @OA\Response(response=200, description="Messages list"),
 *     @OA\Response(response=403, description="Forbidden")
 * )
 *
 * @OA\Post(
 *     path="/api/chat/conversations/{conversationId}/messages",
 *     operationId="chatSendMessage",
 *     tags={"Chat"},
 *     summary="Send a message",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="conversationId", in="path", required=true,
 *
 *         @OA\Schema(type="integer")
 *     ),
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"message"},
 *
 *             @OA\Property(property="message", type="string")
 *         )
 *     ),
 *
 *     @OA\Response(response=201, description="Message sent")
 * )
 *
 * @OA\Delete(
 *     path="/api/chat/messages/{messageId}",
 *     operationId="chatDeleteMessage",
 *     tags={"Chat"},
 *     summary="Delete a message",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="messageId", in="path", required=true,
 *
 *         @OA\Schema(type="integer")
 *     ),
 *
 *     @OA\Response(response=200, description="Deleted"),
 *     @OA\Response(response=403, description="Forbidden")
 * )
 */
class ChatDocs {}
