<?php

namespace App\Modules\Console\Services;

use App\DTOs\Auth\StaffAccount;
use App\Enums\ComplaintStatus;
use App\Models\Complaint;
use App\Models\User;
use App\Modules\Communication\Contracts\NotificationPort;
use App\Modules\Console\Contracts\EmployeeRepositoryInterface;
use App\Modules\Console\Models\Employee;
use App\Modules\Identity\Contracts\UserDirectory;
use App\Support\Logging\SafeExceptionLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class StaffComplaintService
{
    /** Stage 2 C2: assignees resolved for the list currently being formatted, keyed by employee id. */
    private array $agentCache = [];

    /**
     * Stage 2 C3: the complainants resolved for the list currently being formatted, keyed by user id.
     * The values are Identity's `UserSummary` objects, read by PROPERTY only - this module may not name the
     * DTO class (it reaches Identity through Contracts), so the type is deliberately `object`.
     *
     * @var array<int, object>
     */
    private array $userCache = [];

    private bool $usersPreloaded = false;

    public function __construct(
        private readonly NotificationPort $notificationService,
        private readonly EmployeeRepositoryInterface $employees,
        private readonly UserDirectory $users,
    ) {}

    // ── eager-load for every query ──────────────────────────────────────────
    private const WITH = [
        'user:id,first_name,last_name,email',
        // Stage 2 C2: `assignedAgent` is gone - `format()` reads the assignee from the batched
        // EmployeeRepositoryInterface lookup (`preloadAgents()`), the same ONE query the eager load cost.
        'attachments',
    ];

    /**
     * Stage 2 C3: list queries no longer eager-load the complainant's row; the list controllers call
     * `preloadUsers()` and the same ONE query arrives from Identity's directory instead.
     */
    private const WITH_LIST = [
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
        ?string $status = null,
        ?string $type = null,
        ?string $date = null,
        ?int $userId = null,
        int $perPage = 15,
        int $page = 1,
    ): LengthAwarePaginator {
        $query = Complaint::with(self::WITH_LIST)
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
     * @param  string|null  $status  further filter (resolved|closed) for history
     */
    public function listEscalated(
        ?string $status = null,
        ?string $type = null,
        ?string $date = null,
        int $perPage = 15,
        int $page = 1,
    ): LengthAwarePaginator {
        $query = Complaint::with(self::WITH_LIST);

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
                'status' => ComplaintStatus::IN_REVIEW,
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
     * @throws \DomainException if the complaint is escalated or already closed.
     */
    public function respond(
        int $complaintId,
        string $resolutionNotes,
        ComplaintStatus $newStatus,
        Employee $agent,
    ): Complaint {
        $complaint = Complaint::with(self::WITH)->findOrFail($complaintId);

        if ($complaint->status === ComplaintStatus::ESCALATED) {
            throw new \DomainException(
                'This complaint has been escalated to admin. You can no longer respond to it.'
            );
        }

        if (! $complaint->status->isAgentActionable()) {
            throw new \DomainException(
                "Cannot respond to a complaint with status '{$complaint->status->label()}'."
            );
        }

        $updates = [
            'status' => $newStatus,
            'resolution_notes' => $resolutionNotes,
            'assigned_to' => $complaint->assigned_to ?? $agent->id,
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
     * @throws \DomainException if complaint cannot be escalated.
     */
    public function escalate(
        int $complaintId,
        string $reason,
        Employee $agent,
    ): Complaint {
        $complaint = Complaint::with(self::WITH)->findOrFail($complaintId);

        if ($complaint->status === ComplaintStatus::ESCALATED) {
            throw new \DomainException('This complaint is already escalated.');
        }

        if (! $complaint->status->isAgentActionable()) {
            throw new \DomainException(
                "Cannot escalate a complaint with status '{$complaint->status->label()}'."
            );
        }

        $agentName = $agent->fullName();
        $escalationLog = "[ESCALATED by {$agentName} at ".now()->toDateTimeString()."]\n{$reason}";

        $complaint->update([
            'status' => ComplaintStatus::ESCALATED,
            'assigned_to' => null,         // leaves agent queue
            'resolution_notes' => $escalationLog,
        ]);

        return $complaint->fresh(self::WITH);
    }

    // =========================================================================
    // RESOLVE ESCALATED  (admin / system admin)
    // =========================================================================

    /**
     * Admin resolves an escalated complaint.
     *
     * @throws \DomainException if the complaint is not in escalated status.
     */
    public function resolveEscalated(
        int $complaintId,
        string $resolutionNotes,
        ComplaintStatus $newStatus,
        Employee $admin,
    ): Complaint {
        $complaint = Complaint::with(self::WITH)->findOrFail($complaintId);

        if ($complaint->status !== ComplaintStatus::ESCALATED) {
            throw new \DomainException(
                'Only escalated complaints can be resolved through this action.'
            );
        }

        if (! in_array($newStatus, [ComplaintStatus::RESOLVED, ComplaintStatus::CLOSED])) {
            throw new \DomainException('Escalated complaints can only be set to resolved or closed.');
        }

        $adminName = $admin->fullName();
        $adminLog = "\n\n[RESOLVED by Admin {$adminName} at ".now()->toDateTimeString()."]\n{$resolutionNotes}";

        $complaint->update([
            'status' => $newStatus,
            'assigned_to' => $admin->id,
            'resolution_notes' => ($complaint->resolution_notes ?? '').$adminLog,
            'resolved_at' => now(),
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

    /**
     * Stage 2 C2: resolve the assignees for a whole page in ONE query before formatting the rows. Callers
     * that skip this still work - `format()` falls back to one lookup for that row, the lazy query the
     * removed relation used to run.
     */
    public function preloadAgents(iterable $complaints): void
    {
        $ids = [];
        foreach ($complaints as $complaint) {
            if ($complaint->assigned_to) {
                $ids[] = (int) $complaint->assigned_to;
            }
        }

        $this->agentCache = $this->employees->accountsFor($ids);
    }

    private function agentFor(?int $employeeId): ?StaffAccount
    {
        if ($employeeId === null) {
            return null;
        }

        if (array_key_exists($employeeId, $this->agentCache)) {
            return $this->agentCache[$employeeId];
        }

        return $this->employees->accountsFor([$employeeId])[$employeeId] ?? null;
    }

    /**
     * Stage 2 C3: resolve the complainants for a whole page in ONE Identity query before formatting the rows.
     * The list queries no longer eager-load `user`; callers that skip this fall back to the loaded relation
     * (the mutation paths keep it, because a notification needs the model).
     */
    public function preloadUsers(iterable $complaints): void
    {
        $ids = [];
        foreach ($complaints as $complaint) {
            if ($complaint->user_id) {
                $ids[] = (int) $complaint->user_id;
            }
        }

        $this->userCache = $this->users->summariesFor($ids);
        $this->usersPreloaded = true;
    }

    /**
     * The `user` block of the response: the preloaded summary when the list path supplied one, otherwise the
     * relation the mutation paths already eager-load. Identity's DTO is read by property only - this module
     * never names its class.
     */
    private function userBlock(Complaint $complaint): array
    {
        if ($this->usersPreloaded) {
            $summary = $this->userCache[(int) $complaint->user_id] ?? null;

            return [
                'id' => $summary?->id,
                'name' => $summary !== null ? $summary->fullName() : '',
                'email' => $summary?->email,
            ];
        }

        $user = $complaint->user;

        return [
            'id' => $user?->id,
            'name' => trim(($user?->first_name ?? '').' '.($user?->last_name ?? '')),
            'email' => $user?->email,
        ];
    }

    public function format(Complaint $complaint): array
    {
        $agent = $this->agentFor($complaint->assigned_to ? (int) $complaint->assigned_to : null);

        return [
            'id' => $complaint->id,
            'title' => $complaint->title,
            'description' => $complaint->description,
            'type' => $complaint->type?->value,
            'type_label' => $complaint->type?->label(),
            'status' => $complaint->status->value,
            'status_label' => $complaint->status->label(),
            'status_color' => $complaint->status->color(),
            'is_escalated' => $complaint->status === ComplaintStatus::ESCALATED,
            'resolution_notes' => $complaint->resolution_notes,
            'resolved_at' => $complaint->resolved_at?->toIso8601String(),
            'assigned_to' => $agent ? [
                'id' => $agent->id,
                'name' => $agent->fullName(),
                'role' => $agent->role->value,
            ] : null,
            'user' => $this->userBlock($complaint),
            'attachments' => $complaint->attachments?->map(fn ($a) => [
                'id' => $a->id,
                'url' => $a->url,
                'original_name' => $a->original_name,
                'mime_type' => $a->mime_type,
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
                $this->notificationService->createNotification(
                    $user,
                    $type,
                    $title,
                    $message,
                    ['complaint_id' => $complaint->id],
                    'high',
                    'system'
                );
            }
        } catch (\Throwable $e) {
            // T3-13: non-fatal by intent, but it must be visible.
            Log::warning('staff complaint status notification failed (non-fatal): '.SafeExceptionLog::message($e));
        }
    }

    /**
     * Tell the complainant their complaint moved. Non-fatal: a failed
     * notification must not undo the response that was already persisted.
     */
    public function notifyStatusChange(Complaint $complaint, int $complaintId, ComplaintStatus $newStatus): void
    {
        try {
            $complaint->loadMissing('user');
            if ($complaint->user) {
                $this->notificationService->createNotification(
                    $complaint->user,
                    'complaint_response',
                    'رد على شكواك',
                    "تم تحديث حالة شكواك إلى: {$newStatus->label()}.",
                    ['complaint_id' => $complaintId],
                    'normal',
                    'system'
                );
            }
        } catch (\Throwable $e) {
            Log::warning('staff complaint status notification failed (non-fatal): '.SafeExceptionLog::message($e));
        }
    }

    // ── Private: tab badge counts ─────────────────────────────────────────────
    public function statusCounts(): array
    {
        // GROUP BY aggregate drives all 5 tab badges; called on every index() load.
        // Busted by escalate(), show(), respond() in this controller.
        // New complaints from the passenger side self-heal within 1 min.
        return Cache::remember('staff.complaint-counts', now()->addMinutes(1), function () {
            $rows = Complaint::query()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->toArray();

            return [
                'all' => array_sum($rows),
                'pending' => $rows[ComplaintStatus::PENDING->value] ?? 0,
                'in_review' => $rows[ComplaintStatus::IN_REVIEW->value] ?? 0,
                'resolved' => $rows[ComplaintStatus::RESOLVED->value] ?? 0,
                'closed' => $rows[ComplaintStatus::CLOSED->value] ?? 0,
            ];
        });
    }

    /**
     * The three tab counts on the escalated-complaints page.
     *
     * Moved out of StaffAdminController (batch 227) so the controller holds no Eloquent reference.
     * Same cache key, same 1-minute TTL, same three COUNT queries, and it is busted by
     * resolveEscalated() exactly as before.
     *
     * @return array<string, int>
     */
    public function escalatedStatusCounts(): array
    {
        // 3 COUNT queries on every admin complaint page open.
        // Busted by resolveEscalated(); self-heals within 1 min for cross-controller escalations.
        return Cache::remember('staff.escalated-counts', now()->addMinutes(1), function () {
            return [
                'escalated' => Complaint::where('status', ComplaintStatus::ESCALATED->value)->count(),
                'resolved' => Complaint::where('status', ComplaintStatus::RESOLVED->value)->count(),
                'closed' => Complaint::where('status', ComplaintStatus::CLOSED->value)->count(),
            ];
        });
    }
}
