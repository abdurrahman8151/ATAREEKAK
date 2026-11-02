<?php

namespace App\Modules\Console\Services;

use App\Models\Employee;
use App\Modules\Console\Contracts\SupportAgentRoutingInterface;

/**
 * Support-agent routing: which agent a new /api/contact conversation should go to.
 *
 * Moved here from EmployeeManagementService in batch 227 (the same batch that moved it there from
 * ContactController). The service file had crossed 500 lines, which the file-length floor of the
 * previous batch brought to zero; keeping this single query in its own class keeps both rules true.
 * The SQL is unchanged, character for character.
 */
final class SupportAgentRoutingService implements SupportAgentRoutingInterface
{
    /**
     * Pick the least-loaded active support agent.
     *
     * "Least-loaded" = fewest open support conversations.
     * The subquery joins through the shadow User account (matched by email) so we never need a
     * direct Employee -> Conversation relationship.
     */
    public function leastLoadedSupportAgent(): ?Employee
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
}
