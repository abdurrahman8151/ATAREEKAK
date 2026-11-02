<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Modules\Communication\Contracts\ChatRepositoryInterface;
use App\Modules\Console\Contracts\EmployeeManagementInterface;
use App\Modules\Console\Contracts\SupportAgentRoutingInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContactController extends Controller
{
    public function __construct(
        private readonly ChatRepositoryInterface $chatRepository,
        private readonly EmployeeManagementInterface $managementService,
        private readonly SupportAgentRoutingInterface $agentRouting,
    ) {}

    // POST /api/contact
    public function __invoke(Request $request): JsonResponse
    {
        $agent = $this->agentRouting->leastLoadedSupportAgent();

        if (! $agent) {
            return $this->errorResponse('No support agents are available at the moment.', 503);
        }

        // Ensure the agent's shadow User account exists (permanent self-heal).
        $agentUser = $this->managementService->ensureShadowUser($agent);
        $currentUser = $request->user();

        // Edge case: authenticated user IS the agent.
        if ($currentUser->id === $agentUser->id) {
            return $this->errorResponse('Cannot open support chat.', 422);
        }

        // Return existing support conversation if one already exists.
        $existing = $this->chatRepository->findSupportConversation($currentUser, $agentUser);

        if ($existing) {
            return $this->successResponse($existing->id, 'Support chat ready.', $agent);
        }

        // Create a new support conversation with correct type and per-role.
        $conversation = $this->startConversation($currentUser->id, $agentUser->id);

        return $this->successResponse($conversation->id, 'Support chat started.', $agent, 201);
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

    private function successResponse(int $conversationId, string $message, $agent, int $status = 200): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'conversation_id' => $conversationId,
            'message' => $message,
            'agent' => [
                'name' => $agent->first_name.' '.$agent->last_name,
            ],
        ], $status);
    }
}
