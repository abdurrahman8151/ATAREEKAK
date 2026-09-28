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
        $agent = Employee::where('role', 'support_agent')
            ->where('is_active', true)
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
