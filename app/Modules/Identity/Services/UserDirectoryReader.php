<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Contracts\UserDirectory;
use App\Modules\Identity\DTOs\UserSummary;

/**
 * Stage 2 C3: the `users` table is Identity's to read; everyone else asks here.
 *
 * One query per call, whatever the number of ids - that is the whole point of the batched method.
 * The columns are listed explicitly rather than `select('*')` so a later column (a password hash, a
 * token version) cannot leak into a DTO by accident.
 */
final class UserDirectoryReader implements UserDirectory
{
    private const COLUMNS = [
        'id',
        'first_name',
        'last_name',
        'email',
        'status',
        'is_verified_passenger',
        'is_verified_driver',
        'gender',
        'address',
        'verification_status',
        'updated_at',
    ];

    public function summaryFor(int $userId): ?UserSummary
    {
        return $this->summariesFor([$userId])[$userId] ?? null;
    }

    public function summariesFor(array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));

        if ($ids === []) {
            return [];
        }

        $summaries = [];

        foreach (User::query()->whereIn('id', $ids)->get(self::COLUMNS) as $user) {
            $summaries[(int) $user->id] = $this->toSummary($user);
        }

        return $summaries;
    }

    /**
     * Stage 2 C3: the verification queues' list, owned here because the query is over `users`. The statement
     * shape is the one Console ran itself - same filter, and `$newestFirst` reproduces the staff queue's
     * `orderByDesc('updated_at')` while the admin queue keeps the engine's own order, so both responses stay
     * byte-identical.
     *
     * @return array<int, UserSummary>
     */
    public function pendingVerificationUsers(bool $newestFirst = false): array
    {
        $query = User::query()->where('verification_status', 'pending');

        if ($newestFirst) {
            $query->orderByDesc('updated_at');
        }

        return $query->get(self::COLUMNS)
            ->map(fn (User $user) => $this->toSummary($user))
            ->values()
            ->all();
    }

    private function toSummary(User $user): UserSummary
    {
        return new UserSummary(
            id: (int) $user->id,
            firstName: $user->first_name,
            lastName: $user->last_name,
            email: $user->email,
            status: $user->status,
            isVerifiedPassenger: (bool) $user->is_verified_passenger,
            isVerifiedDriver: (bool) $user->is_verified_driver,
            gender: $user->gender,
            address: $user->address,
            verificationStatus: $user->verification_status,
            updatedAt: $user->updated_at,
        );
    }
}
