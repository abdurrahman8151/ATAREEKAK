<?php

namespace App\Repositories;

use App\Models\Complaint;
use App\Modules\Console\Contracts\ComplaintRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ComplaintRepository implements ComplaintRepositoryInterface
{
    public function create(array $data): Complaint
    {
        return Complaint::create($data);
    }

    public function findById(int $id): ?Complaint
    {
        return Complaint::with(['assignedAgent:id,first_name,last_name', 'attachments'])->find($id);
    }

    public function getUserComplaints(int $userId): Collection
    {
        return Complaint::where('user_id', $userId)
            ->with(['assignedAgent:id,first_name,last_name', 'attachments'])
            ->orderByDesc('created_at')
            ->get();
    }
}
