<?php

namespace App\Modules\Console\Repositories;

use App\Modules\Console\Contracts\ComplaintRepositoryInterface;
use App\Modules\Console\Models\Complaint;
use Illuminate\Database\Eloquent\Collection;

class ComplaintRepository implements ComplaintRepositoryInterface
{
    public function create(array $data): Complaint
    {
        return Complaint::create($data);
    }

    public function findById(int $id): ?Complaint
    {
        // Stage 2 C2: no `assignedAgent` relation any more - the services resolve the assignee through
        // EmployeeRepositoryInterface::accountsFor() in ONE query.
        return Complaint::with(['attachments'])->find($id);
    }

    public function getUserComplaints(int $userId): Collection
    {
        return Complaint::where('user_id', $userId)
            ->with(['attachments'])
            ->orderByDesc('created_at')
            ->get();
    }
}
