<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\ChatRepositoryInterface;
use App\Models\Employee;
use App\Models\User;
use App\Services\Staff\EmployeeManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContactController extends Controller
{
    public function __construct(
        private readonly ChatRepositoryInterface   $chatRepository,
        private readonly EmployeeManagementService $managementService,
    ) {}

    // POST /api/contact
    public function __invoke(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        // Existing thread wins — check BEFORE choosing an agent.
        //
        // Agent assignment is load-balanced, so the "least-loaded" agent changes
        // as other customers open chats. Picking the agent first and then looking
        // for a conversation with *that* agent meant a returning customer whose
        // agent was no longer the least-loaded one matched nothing, and every
        // visit to the support page started a brand-new chat.
        $existing = $this->chatRepository->findSupportConversationForUser($currentUser);

        if ($existing) {
            return response()->json([
                'status'          => 'success',
                'conversation_id' => $existing->id,
                'message'         => 'Support chat ready.',
                'agent'           => [
                    'name' => $this->agentName($existing->getOtherParticipant($currentUser)),
                ],
            ]);
        }

        // Pick the least-loaded active support agent.
        //
        // "Least-loaded" = fewest open support conversations.
        // The subquery joins through the shadow User account (matched by email)
        // so we never need a direct Employee → Conversation relationship.
        $agent = Employee::where('role', 'support_agent')
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

        if (!$agent) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No support agents are available at the moment.',
            ], 503);
        }

        // Ensure the agent's shadow User account exists (permanent self-heal).
        $agentUser = $this->managementService->ensureShadowUser($agent);

        // Edge case: authenticated user IS the agent.
        if ($currentUser->id === $agentUser->id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cannot open support chat.',
            ], 422);
        }

        // Create a new support conversation with correct type and per-role.
        $conversation = $this->chatRepository->createConversation(
            participants: [$currentUser->id, $agentUser->id],
            type:         'support',
            title:        null,
            roles:        [
                $currentUser->id => 'customer',
                $agentUser->id   => 'agent',
            ],
        );

        return response()->json([
            'status'          => 'success',
            'conversation_id' => $conversation->id,
            'message'         => 'Support chat started.',
            'agent'           => [
                'name' => $agent->first_name . ' ' . $agent->last_name,
            ],
        ], 201);
    }

    /**
     * Display name for the agent already sitting on an existing conversation.
     *
     * Prefers the Employee record (matched through the shadow User's email) so
     * the customer sees the same name staff-side edits produce; falls back to
     * the shadow User itself.
     */
    private function agentName(?User $agentUser): ?string
    {
        if (!$agentUser) {
            return null;
        }

        $agent = Employee::where('email', $agentUser->email)->first();

        return $agent
            ? trim($agent->first_name . ' ' . $agent->last_name)
            : trim($agentUser->first_name . ' ' . $agentUser->last_name);
    }
}
