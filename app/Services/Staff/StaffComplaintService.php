<?php

namespace App\Services\Staff;

use App\Enums\ComplaintStatus;
use App\Models\Complaint;
use App\Models\Employee;
use App\Models\NotificationService;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class StaffComplaintService
{
    // ── eager-load for every query ──────────────────────────────────────────
    private const WITH = [
        'user:id,first_name,last_name,email',
        'assignedAgent:id,first_name,last_name,role',
        'attachments',
    ];

    // =========================================================================
    // AGENT VIEW  —  non-escalated queue
    // =========================================================================

    /**
     * Paginated list of complaints the agent can see.
     * Escalated complaints are deliberately excluded (they belong to admin now).
     */
    public function listAll(
        ?string $status,
        ?string $type,
        ?string $date,
        ?int    $userId,
        int     $perPage = 15,
        int     $page    = 1,
    ): LengthAwarePaginator {
        $query = Complaint::with(self::WITH)
            // Never show escalated in the agent's queue
            ->where('status', '!=', ComplaintStatus::ESCALATED->value);

        if ($status) {
            $query->where('status', $status);
        }

        if ($type) {
            $query->where('type', $type);
        }

        if ($date === 'last_7_days') {
            $query->where('created_at', '>=', now()->subDays(7));
        } elseif ($date === 'last_30_days') {
            $query->where('created_at', '>=', now()->subDays(30));
        }

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    // =========================================================================
    // ADMIN VIEW  —  escalated queue
    // =========================================================================

    /**
     * Paginated list of escalated complaints for admin / system admin.
     *
     * @param  string|null  $status   further filter (resolved|closed) for history
     */
    public function listEscalated(
        ?string $status,
        ?string $type,
        ?string $date,
        int     $perPage = 15,
        int     $page    = 1,
    ): LengthAwarePaginator {
        // Scope to complaints that were actually escalated at some point —
        // this is what makes it the *escalated* view. status alone isn't
        // enough: resolveEscalated() moves status away from 'escalated', so
        // a bare status filter would match every resolved/closed complaint
        // platform-wide, escalated or not (BUG-10).
        $query = Complaint::with(self::WITH)->whereNotNull('escalated_at');

        if ($status) {
            // Admin can filter e.g. resolved escalated for history
            $query->where('status', $status);
        } else {
            // Default: only open escalated ones (their active queue)
            $query->where('status', ComplaintStatus::ESCALATED->value);
        }

        if ($type) {
            $query->where('type', $type);
        }

        if ($date === 'last_7_days') {
            $query->where('created_at', '>=', now()->subDays(7));
        } elseif ($date === 'last_30_days') {
            $query->where('created_at', '>=', now()->subDays(30));
        }

        return $query
            ->orderByDesc('updated_at')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    // =========================================================================
    // OPEN  (GET show — auto-assign to agent)
    // =========================================================================

    /**
     * Fetch a single complaint. If it is PENDING and unassigned, automatically
     * move it to IN_REVIEW and assign it to the requesting agent.
     *
     * Escalated complaints can be viewed (read-only) but are not reassigned.
     */
    public function openComplaint(int $complaintId, Employee $agent): Complaint
    {
        $complaint = Complaint::with(self::WITH)->findOrFail($complaintId);

        // Escalated → read-only for agents, do not touch
        if ($complaint->status === ComplaintStatus::ESCALATED) {
            return $complaint;
        }

        // Auto-assign when an agent first opens a PENDING complaint
        if (
            $complaint->status === ComplaintStatus::PENDING
            && is_null($complaint->assigned_to)
        ) {
            $complaint->update([
                'status'      => ComplaintStatus::IN_REVIEW,
                'assigned_to' => $agent->id,
            ]);
        }

        return $complaint->fresh(self::WITH);
    }

    // =========================================================================
    // RESPOND  (agent — in_review / resolved / closed)
    // =========================================================================

    /**
     * Agent responds to a complaint and optionally changes its status.
     *
     * @throws \DomainException  if the complaint is escalated or already closed.
     */
    public function respond(
        int            $complaintId,
        string         $resolutionNotes,
        ComplaintStatus $newStatus,
        Employee       $agent,
    ): Complaint {
        $complaint = Complaint::with(self::WITH)->findOrFail($complaintId);

        if ($complaint->status === ComplaintStatus::ESCALATED) {
            throw new \DomainException(
                'This complaint has been escalated to admin. You can no longer respond to it.'
            );
        }

        if (!$complaint->status->isAgentActionable()) {
            throw new \DomainException(
                "Cannot respond to a complaint with status '{$complaint->status->label()}'."
            );
        }

        $updates = [
            'status'           => $newStatus,
            'resolution_notes' => $resolutionNotes,
            'assigned_to'      => $complaint->assigned_to ?? $agent->id,
        ];

        if (in_array($newStatus, [ComplaintStatus::RESOLVED, ComplaintStatus::CLOSED])) {
            $updates['resolved_at'] = now();

            // Notify the user that their complaint has been handled
            $this->notifyUser(
                $complaint,
                'complaint_resolved',
                'تم الرد على شكواك',
                "تم معالجة شكواك \"{$complaint->title}\" وتحديث حالتها إلى: {$newStatus->label()}."
            );
        }

        $complaint->update($updates);

        return $complaint->fresh(self::WITH);
    }

    // =========================================================================
    // ESCALATE  (agent → admin)
    // =========================================================================

    /**
     * Agent escalates a complaint, transferring responsibility to admin.
     *
     * What changes:
     *   - status      → ESCALATED
     *   - assigned_to → null  (removed from agent queue)
     *   - resolution_notes prepended with escalation context
     *
     * @throws \DomainException  if complaint cannot be escalated.
     */
    public function escalate(
        int      $complaintId,
        string   $reason,
        Employee $agent,
    ): Complaint {
        $complaint = Complaint::with(self::WITH)->findOrFail($complaintId);

        if ($complaint->status === ComplaintStatus::ESCALATED) {
            throw new \DomainException('This complaint is already escalated.');
        }

        if (!$complaint->status->isAgentActionable()) {
            throw new \DomainException(
                "Cannot escalate a complaint with status '{$complaint->status->label()}'."
            );
        }

        $agentName    = $agent->fullName();
        $escalationLog = "[ESCALATED by {$agentName} at " . now()->toDateTimeString() . "]\n{$reason}";

        $complaint->update([
            'status'           => ComplaintStatus::ESCALATED,
            'assigned_to'      => null,         // leaves agent queue
            'resolution_notes' => $escalationLog,
            'escalated_at'     => now(),        // never cleared — true escalation history
        ]);

        return $complaint->fresh(self::WITH);
    }

    // =========================================================================
    // RESOLVE ESCALATED  (admin / system admin)
    // =========================================================================

    /**
     * Admin resolves an escalated complaint.
     *
     * @throws \DomainException  if the complaint is not in escalated status.
     */
    public function resolveEscalated(
        int            $complaintId,
        string         $resolutionNotes,
        ComplaintStatus $newStatus,
        Employee       $admin,
    ): Complaint {
        $complaint = Complaint::with(self::WITH)->findOrFail($complaintId);

        if ($complaint->status !== ComplaintStatus::ESCALATED) {
            throw new \DomainException(
                'Only escalated complaints can be resolved through this action.'
            );
        }

        if (!in_array($newStatus, [ComplaintStatus::RESOLVED, ComplaintStatus::CLOSED])) {
            throw new \DomainException('Escalated complaints can only be set to resolved or closed.');
        }

        $adminName  = $admin->fullName();
        $adminLog   = "\n\n[RESOLVED by Admin {$adminName} at " . now()->toDateTimeString() . "]\n{$resolutionNotes}";

        $complaint->update([
            'status'           => $newStatus,
            'assigned_to'      => $admin->id,
            'resolution_notes' => ($complaint->resolution_notes ?? '') . $adminLog,
            'resolved_at'      => now(),
        ]);

        // Notify the user
        $this->notifyUser(
            $complaint,
            'complaint_resolved',
            'تم حل شكواك المُصعَّدة',
            "تمت معالجة شكواك \"{$complaint->title}\" من قِبَل الإدارة. الحالة: {$newStatus->label()}."
        );

        return $complaint->fresh(self::WITH);
    }

    // =========================================================================
    // FORMAT
    // =========================================================================

    public function format(Complaint $complaint): array
    {
        return [
            'id'               => $complaint->id,
            'title'            => $complaint->title,
            'description'      => $complaint->description,
            'type'             => $complaint->type?->value,
            'type_label'       => $complaint->type?->label(),
            'status'           => $complaint->status->value,
            'status_label'     => $complaint->status->label(),
            'status_color'     => $complaint->status->color(),
            // True escalation history, not just "currently escalated" — a
            // resolved complaint that passed through escalation still reads
            // is_escalated: true (see BUG-10).
            'is_escalated'     => $complaint->escalated_at !== null,
            'resolution_notes' => $complaint->resolution_notes,
            'resolved_at'      => $complaint->resolved_at?->toIso8601String(),
            'assigned_to'      => $complaint->assignedAgent ? [
                'id'   => $complaint->assignedAgent->id,
                'name' => $complaint->assignedAgent->fullName(),
                'role' => $complaint->assignedAgent->role->value,
            ] : null,
            'user' => [
                'id'    => $complaint->user?->id,
                'name'  => trim(($complaint->user?->first_name ?? '') . ' ' . ($complaint->user?->last_name ?? '')),
                'email' => $complaint->user?->email,
            ],
            'attachments' => $complaint->attachments?->map(fn ($a) => [
                    'id'            => $a->id,
                    'url'           => $a->url,
                    'original_name' => $a->original_name,
                    'mime_type'     => $a->mime_type,
                ])->values() ?? [],
            'created_at' => $complaint->created_at->toIso8601String(),
            'updated_at' => $complaint->updated_at->toIso8601String(),
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function notifyUser(Complaint $complaint, string $type, string $title, string $message): void
    {
        try {
            $user = User::find($complaint->user_id);
            if ($user) {
                app(\App\Services\NotificationService::class)->createNotification(
                    $user,
                    $type,
                    $title,
                    $message,
                    ['complaint_id' => $complaint->id],
                    'high',
                    'system'
                );
            }
        } catch (\Throwable) {
            // Notification failure must never break the main action
        }
    }
}
