<?php

namespace App\Modules\Console\Services;

use App\Enums\ComplaintStatus;
use App\Enums\ComplaintType;
use App\Models\Complaint;
use App\Modules\Console\Contracts\ComplaintDirectory;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Stage 2 C2: Console owns `complaints`, so the passenger-profile complaint reads run here.
 *
 * The three methods are line-for-line the queries Identity's panels ran - including the `assignedAgent`
 * eager load and, on the paginated one, the `attachments` eager load, so the endpoint's query count does
 * not move - and each row is flattened to the five scalars the response prints. The type/status values are
 * normalised here, where the model's casts live, so the caller cannot get the enum-vs-string difference
 * wrong.
 */
final class ComplaintDirectoryService implements ComplaintDirectory
{
    public function pageForUser(
        int $userId,
        string $status,
        string $type,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        $query = Complaint::with(['assignedAgent:id,first_name,last_name', 'attachments'])
            ->where('user_id', $userId);

        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($type !== 'all') {
            $query->where('type', $type);
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (Complaint $complaint) => $this->row($complaint));
    }

    public function recentForUser(int $userId, string $status, string $type, int $limit): array
    {
        $query = Complaint::with(['assignedAgent:id,first_name,last_name'])
            ->where('user_id', $userId);

        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($type !== 'all') {
            $query->where('type', $type);
        }

        return $query
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Complaint $complaint) => $this->row($complaint))
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
     * @return array{id: int, type: string, status: string, created_at: Carbon, assigned_to: string|null}
     */
    private function row(Complaint $complaint): array
    {
        $assigned = $complaint->assignedAgent;

        return [
            'id' => $complaint->id,
            'type' => $complaint->type instanceof ComplaintType
                ? $complaint->type->value : (string) $complaint->type,
            'status' => $complaint->status instanceof ComplaintStatus
                ? $complaint->status->value : (string) $complaint->status,
            'created_at' => $complaint->created_at,
            'assigned_to' => $assigned
                ? trim("{$assigned->first_name} {$assigned->last_name}")
                : null,
        ];
    }
}
