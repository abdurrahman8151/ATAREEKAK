<?php

namespace App\Services\Complaint;

use App\Enums\ComplaintStatus;
use App\Enums\ComplaintType;
use App\Interfaces\ComplaintRepositoryInterface;
use App\Models\Complaint;
use App\Models\ComplaintAttachment;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class ComplaintService
{
    public function __construct(
        private readonly ComplaintRepositoryInterface $repository,
    ) {}

    public function submit(array $data, User $user, array $files = []): Complaint
    {
        // RV-23 (slice 2): least-loaded assignment instead of head-of-queue.
        //
        // BEFORE: `Employee::where('role','support_agent')->where('is_active',true)->first()`
        // — no ordering, so EVERY complaint landed on the same first-matched agent.
        // With one busy agent and ten idle ones, the queue starved one person while the
        // rest never saw work: an availability-shaped bug, not just unfairness (the busy
        // agent's backlog grows and SLA degrades while capacity sits idle).
        //
        // Mirrors the convention already proven in ContactController (a load-count
        // subquery, ASC, first()). Complaints relate to employees DIRECTLY via
        // assigned_to, so unlike ContactController this needs no email join. "Loaded" =
        // complaints still needing handling (everything except the terminal resolved /
        // closed states); a brand-new complaint (this insert, pending) is not counted
        // against the chosen agent at query time, matching the pre-insert ordering.
        //
        // Eligibility is UNCHANGED — same role, same active filter, same null-tolerant
        // result — so this strictly redistributes, grants no new access, and never
        // weakens validation. A deterministic id tie-break is added (the original had
        // none, leaving the choice arbitrary and a fairness assertion inherently flaky).
        $agent = Employee::where('role', 'support_agent')
            ->where('is_active', true)
            ->orderByRaw("(
                SELECT COUNT(*)
                FROM complaints
                WHERE complaints.assigned_to = employees.id
                  AND complaints.status NOT IN ('resolved', 'closed')
            ) ASC")
            ->orderBy('employees.id')
            ->first();

        $complaint = $this->repository->create([
            'user_id' => $user->id,
            'assigned_to' => $agent?->id,
            'title' => $data['title'],
            'description' => $data['description'],
            'type' => ComplaintType::from($data['type'])->value,
            'status' => ComplaintStatus::PENDING->value,
        ]);

        // Store attachments (max 3, already validated in controller)
        foreach (array_slice($files, 0, 3) as $file) {
            $path = $file->store("complaints/{$complaint->id}", 'public');

            ComplaintAttachment::create([
                'complaint_id' => $complaint->id,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ]);
        }

        return $complaint->load('attachments');
    }

    public function getUserComplaints(User $user): Collection
    {
        return $this->repository->getUserComplaints($user->id);
    }

    /**
     * @throws \DomainException if complaint doesn't belong to user
     */
    public function getForUser(int $id, User $user): Complaint
    {
        $complaint = $this->repository->findById($id);

        if (! $complaint || $complaint->user_id !== $user->id) {
            throw new \DomainException('Complaint not found.');
        }

        return $complaint;
    }

    public function format(Complaint $complaint): array
    {
        $complaint->loadMissing('attachments');

        return [
            'id' => $complaint->id,
            'title' => $complaint->title,
            'description' => $complaint->description,
            'type' => $complaint->type->value,
            'type_label' => $complaint->type->label(),
            'status' => $complaint->status->value,
            'status_label' => $complaint->status->label(),
            'status_color' => $complaint->status->color(),
            'resolution_notes' => $complaint->resolution_notes,
            'assigned_to' => $complaint->assignedAgent ? [
                'name' => $complaint->assignedAgent->first_name.' '.$complaint->assignedAgent->last_name,
            ] : null,
            'attachments' => $complaint->attachments->map(fn ($a) => [
                'id' => $a->id,
                'url' => asset('storage/'.$a->path),
                'original_name' => $a->original_name,
                'mime_type' => $a->mime_type,
                'size_kb' => round($a->size / 1024, 1),
            ])->values(),
            'resolved_at' => $complaint->resolved_at?->toIso8601String(),
            'submitted_at' => $complaint->created_at->toIso8601String(),
        ];
    }
}
