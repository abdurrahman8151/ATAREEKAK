<?php

namespace App\Modules\Console\Services;

use App\Models\User;
use App\Modules\Identity\Contracts\PhotoRepositoryInterface;
use App\Modules\Identity\Contracts\UserDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * The verification action queue: the pending list the admin works through, the rejection, and the
 * cache bust both decisions share.
 *
 * Split out of AdminDashboardController (behaviour unchanged). approveVerification() stays in the
 * controller because it reads and validates a request; it calls bustVerificationCaches() here.
 * Bodies moved verbatim.
 */
final class AdminVerificationQueueService
{
    /**
     * NOT cached.
     * Admins work through this as a live action queue. Caching would show a
     * stale count in the same session immediately after approving/rejecting.
     */
    public function pendingVerifications(): JsonResponse
    {
        // Stage 2 C3: the LIST query over `users` moved to Identity's directory (filter included); the
        // documents still arrive batched from the photos port, so the endpoint's query count does not move.
        $pending = app(UserDirectory::class)->pendingVerificationUsers();

        $documents = app(PhotoRepositoryInterface::class)->documentsFor(
            array_map(fn ($user) => $user->id, $pending)
        );

        $rows = array_map(function ($u) use ($documents) {
            $docs = $documents[$u->id] ?? [];
            $docTypes = array_column($docs, 'type');
            $isDriver = in_array('license', $docTypes) || in_array('mechanic_card', $docTypes);

            return [
                'user_id' => $u->id,
                'name' => trim(($u->firstName ?? '').' '.($u->lastName ?? '')),
                'email' => $u->email,
                'type' => $isDriver ? 'driver' : 'passenger',
                'documents' => collect($docs)->map(fn ($row) => [
                    'type' => $row['type'],
                    'url' => asset("storage/{$row['path']}"),
                ]),
                'submitted_at' => $u->updatedAt->toIso8601String(),
            ];
        }, $pending);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    /**
     * Mutation — not cached. Busts the same caches as approveVerification.
     */
    public function rejectVerification(int $userId): JsonResponse
    {
        $user = User::findOrFail($userId);
        $user->update([
            'verification_status' => 'rejected',
            'is_verified_passenger' => false,
            'is_verified_driver' => false,
        ]);

        $this->bustVerificationCaches();

        return response()->json(['status' => 'success', 'message' => 'Verification rejected']);
    }

    /**
     * Clears all caches that become stale after a verification decision.
     *
     * Covers:
     *   - Main dashboard BFF and stat cards
     *   - Driver management BFF and stat cards
     *   - Verification efficiency (3 period variants)
     */
    public function bustVerificationCaches(): void
    {
        Cache::forget('admin.dashboard.data');
        Cache::forget('admin.dashboard.stats');
        Cache::forget('admin.drivers.dashboard');
        Cache::forget('admin.drivers.stats');

        foreach (['day', 'week', 'month'] as $period) {
            Cache::forget("admin.drivers.verification.efficiency.{$period}");
        }
    }
}
