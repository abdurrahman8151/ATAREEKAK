<?php

namespace App\Modules\Console\Services;

use App\DTOs\Auth\StaffAccount;
use App\Enums\ComplaintStatus;
use App\Enums\ComplaintType;
use App\Modules\Console\Contracts\ComplaintDirectory;
use App\Modules\Console\Contracts\EmployeeRepositoryInterface;
use App\Modules\Console\Models\Complaint;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Stage 2 C2: Console owns `complaints`, so the passenger-profile complaint reads run here.
 *
 * The three methods are the queries Identity's panels ran - same filters, same ordering, same limits - and
 * each row is flattened to the five scalars the response prints. The type/status values are normalised here,
 * where the model's casts live, so the caller cannot get the enum-vs-string difference wrong.
 *
 * Stage 2 C2: the `assignedAgent` relation is gone; the assignee's name arrives from ONE batched
 * `EmployeeRepositoryInterface::accountsFor()` per page (the same one query the eager load cost). The
 * `attachments` eager load that sec 341 kept only to hold a frozen budget is also gone: the row shape has no
 * attachments, so the query was dead weight and its removal is a shrink (the snapshot prints the notice).
 */
final class ComplaintDirectoryService implements ComplaintDirectory
{
    public function __construct(
        private readonly EmployeeRepositoryInterface $employees,
    ) {}

    public function pageForUser(
        int $userId,
        string $status,
        string $type,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        $query = Complaint::where('user_id', $userId);

        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($type !== 'all') {
            $query->where('type', $type);
        }

        $paginator = $query
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $agents = $this->agentsFor($paginator->getCollection()->pluck('assigned_to')->all());

        return $paginator->through(fn (Complaint $complaint) => $this->row($complaint, $agents));
    }

    public function recentForUser(int $userId, string $status, string $type, int $limit): array
    {
        $query = Complaint::where('user_id', $userId);

        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($type !== 'all') {
            $query->where('type', $type);
        }

        $complaints = $query
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $agents = $this->agentsFor($complaints->pluck('assigned_to')->all());

        return $complaints
            ->map(fn (Complaint $complaint) => $this->row($complaint, $agents))
            ->values()
            ->all();
    }

    public function statusCounts(int $userId): array
    {
        return Complaint::where('user_id', $userId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();
    }

    /**
     * @param  array<int, int|null>  $employeeIds
     * @return array<int, StaffAccount>
     */
    private function agentsFor(array $employeeIds): array
    {
        return $this->employees->accountsFor($employeeIds);
    }

    /**
     * @param  array<int, StaffAccount>  $agents
     * @return array{id: int, type: string, status: string, created_at: Carbon, assigned_to: string|null}
     */
    private function row(Complaint $complaint, array $agents): array
    {
        $agent = $complaint->assigned_to ? ($agents[(int) $complaint->assigned_to] ?? null) : null;

        return [
            'id' => $complaint->id,
            'type' => $complaint->type instanceof ComplaintType
                ? $complaint->type->value : (string) $complaint->type,
            'status' => $complaint->status instanceof ComplaintStatus
                ? $complaint->status->value : (string) $complaint->status,
            'created_at' => $complaint->created_at,
            'assigned_to' => $agent?->fullName(),
        ];
    }
}
