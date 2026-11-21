<?php

namespace App\Modules\Communication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Communication\Contracts\ChatRepositoryInterface;
use App\Modules\Communication\Contracts\SupportDeskDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContactController extends Controller
{
    public function __construct(
        private readonly ChatRepositoryInterface $chatRepository,
        // Stage 4 D-1: ONE consumer-declared port replaces Console's two - the agent and its shadow chat account
        // arrive together, and Communication names no Console class at all.
        private readonly SupportDeskDirectory $supportDesk,
    ) {}

    // POST /api/contact
    public function __invoke(Request $request): JsonResponse
    {
        $agent = $this->supportDesk->leastLoadedAgent();

        if (! $agent) {
            return $this->errorResponse('No support agents are available at the moment.', 503);
        }

        // The shadow User account is ensured inside the owner as part of the answer (the same permanent self-heal
        // this endpoint used to run as a second call).
        $agentUser = $agent->shadowUser;
        $currentUser = $request->user();

        // Edge case: authenticated user IS the agent.
        if ($currentUser->id === $agentUser->id) {
            return $this->errorResponse('Cannot open support chat.', 422);
        }

        // Return existing support conversation if one already exists.
        $existing = $this->chatRepository->findSupportConversation($currentUser, $agentUser);

        if ($existing) {
            return $this->successResponse($existing->id, 'Support chat ready.', $agent->name);
        }

        // Create a new support conversation with correct type and per-role.
        $conversation = $this->startConversation($currentUser->id, $agentUser->id);

        return $this->successResponse($conversation->id, 'Support chat started.', $agent->name, 201);
    }

    /**
     * Open the support conversation and let the caller render its id.
     */
    private function startConversation(int $currentUserId, int $agentUserId)
    {
        return $this->chatRepository->createConversation(
            participants: [$currentUserId, $agentUserId],
            type: 'support',
            title: null,
            roles: [
                $currentUserId => 'customer',
                $agentUserId => 'agent',
            ],
        );
    }

    private function errorResponse(string $message, int $status): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
        ], $status);
    }

    /**
     * The response's `agent` block prints the name the owner already joined (`first_name last_name`, trimmed), so
     * the JSON is unchanged by the move.
     */
    private function successResponse(int $conversationId, string $message, string $agentName, int $status = 200): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'conversation_id' => $conversationId,
            'message' => $message,
            'agent' => [
                'name' => $agentName,
            ],
        ], $status);
    }
}
