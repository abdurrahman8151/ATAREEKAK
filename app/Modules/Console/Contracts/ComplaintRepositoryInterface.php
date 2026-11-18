<?php

namespace App\Modules\Console\Contracts;

use App\Modules\Console\Models\Complaint;
use Illuminate\Database\Eloquent\Collection;

interface ComplaintRepositoryInterface
{
    public function create(array $data): Complaint;

    public function findById(int $id): ?Complaint;

    public function getUserComplaints(int $userId): Collection;
}
