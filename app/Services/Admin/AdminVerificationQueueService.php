<?php

namespace App\Services\Admin;

use App\Models\User;
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
        $pending = User::with(['photos', 'profile'])
            ->where('verification_status', 'pending')
            ->get()
            ->map(function ($u) {
                $docTypes = $u->photos->pluck('type')->toArray();
                $isDriver = in_array('license', $docTypes) || in_array('mechanic_card', $docTypes);

                return [
                    'user_id' => $u->id,
                    'name' => trim("{$u->first_name} {$u->last_name}"),
                    'email' => $u->email,
                    'type' => $isDriver ? 'driver' : 'passenger',
                    'documents' => $u->photos->map(fn ($p) => [
                        'type' => $p->type,
                        'url' => asset("storage/{$p->path}"),
                    ]),
                    'submitted_at' => $u->updated_at->toIso8601String(),
                ];
            });

        return response()->json(['status' => 'success', 'data' => $pending]);
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
