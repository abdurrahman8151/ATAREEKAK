<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\ChatRepositoryInterface;
use App\Models\Employee;
use App\Services\Staff\EmployeeManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContactController extends Controller
{
    public function __construct(
        private readonly ChatRepositoryInterface $chatRepository,
        private readonly EmployeeManagementService $managementService,
    ) {}

    // POST /api/contact
    public function __invoke(Request $request): JsonResponse
    {
        $agent = $this->leastLoadedSupportAgent();

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
     * Pick the least-loaded active support agent.
     *
     * "Least-loaded" = fewest open support conversations.
     * The subquery joins through the shadow User account (matched by email)
     * so we never need a direct Employee → Conversation relationship.
     */
    private function leastLoadedSupportAgent(): ?Employee
    {
        return Employee::where('role', 'support_agent')
            ->where('is_active', true)
            ->whereNotNull('email')
            ->orderByRaw('(
                SELECT COUNT(*)
                FROM conversation_participants cp
                INNER JOIN users u ON u.id = cp.user_id
                INNER JOIN conversations c ON c.id = cp.conversation_id
                WHERE u.email = employees.email
                  AND c.type  = "support"
            ) ASC')
            ->first();
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
