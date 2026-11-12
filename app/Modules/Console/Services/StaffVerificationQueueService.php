<?php

namespace App\Modules\Console\Services;

use App\Models\User;
use App\Modules\Identity\Contracts\PhotoRepositoryInterface;
use Illuminate\Support\Facades\Cache;

/**
 * The staff-side verification action queue: the cached pending list, the row a decision acts on, and
 * the two caches every decision busts.
 *
 * Moved out of StaffAdminController (batch 227) so the controller holds no Eloquent reference. The
 * admin surface has its own queue (App\Modules\Console\Services\AdminVerificationQueueService) and its payload
 * differs on purpose: staff documents are served by the staff-authenticated streaming route, not as
 * public asset() URLs. Bodies and cache keys moved verbatim, including the 2-minute TTL.
 */
final class StaffVerificationQueueService
{
    /**
     * The pending queue, cached for 2 minutes.
     *
     * Heavy eager load (photos + profiles for every pending user).
     * Busted explicitly by approveVerification() and rejectVerification().
     * New submissions from the user side self-heal within TTL.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingVerificationList(): array
    {
        return Cache::remember('staff.pending-verifications', now()->addMinutes(2), function () {
            // Stage 2 C2: the document rows are Identity's and arrive batched for the whole page.
            $users = User::with(['profile'])
                ->where('verification_status', 'pending')
                ->orderByDesc('updated_at')
                ->get();

            $documents = app(PhotoRepositoryInterface::class)->documentsFor($users->pluck('id')->all());

            return $users->map(function (User $u) use ($documents) {
                $rows = $documents[$u->id] ?? [];
                $docTypes = array_column($rows, 'type');
                $isDriver = in_array('license', $docTypes) || in_array('mechanic_card', $docTypes);

                return [
                    'user_id' => $u->id,
                    'name' => trim("{$u->first_name} {$u->last_name}"),
                    'email' => $u->email,
                    'gender' => $u->gender,
                    'address' => $u->address,
                    'type' => $isDriver ? 'driver' : 'passenger',
                    'profile_photo' => $u->profile?->profile_photo
                        ? asset('storage/'.$u->profile->profile_photo)
                        : null,
                    'documents' => collect($rows)->map(fn ($row) => [
                        'type' => $row['type'],
                        // Decision 1b: KYC documents are served by the staff-authenticated
                        // streaming route, not as a public asset() URL. The old public URL was
                        // an IDOR: anyone holding it could download a face ID / licence.
                        'url' => route('staff.documents.show', ['photoId' => $row['id']]),
                    ])->values()->all(),
                    'submitted_at' => $u->updated_at->toIso8601String(),
                ];
            })->values()->all();
        });
    }

    /**
     * The row a verification decision acts on. `findOrFail` is kept, so an unknown id still throws
     * ModelNotFoundException and the controller still answers 404.
     */
    public function findUserOrFail(int $userId): User
    {
        return User::findOrFail($userId);
    }

    /**
     * Both caches a decision makes stale: the user leaves the pending list, and their staff profile
     * shows the new verification status.
     */
    public function bustStaffCaches(int $userId): void
    {
        Cache::forget('staff.pending-verifications');
        Cache::forget("staff.user-profile.{$userId}");
    }
}
