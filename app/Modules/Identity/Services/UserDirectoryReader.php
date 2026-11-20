<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Contracts\PhotoRepositoryInterface;
use App\Modules\Identity\Contracts\UserDirectory;
use App\Modules\Identity\Contracts\UserRatingReader;
use App\Modules\Identity\DTOs\UserSummary;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stage 2 C3: the `users` table is Identity's to read; everyone else asks here.
 *
 * One query per call, whatever the number of ids - that is the whole point of the batched method.
 * The columns are listed explicitly rather than `select('*')` so a later column (a password hash, a
 * token version) cannot leak into a DTO by accident. The ban columns are in the list because the DTO
 * publishes `isBannedNow`, which the model computes from them.
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
        'created_at',
        'updated_at',
        'ban_type',
        'ban_expires_at',
    ];

    public function __construct(
        private readonly PhotoRepositoryInterface $photos,
    ) {}

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

    /**
     * Stage 2 C3: the admin report's three `users` counters. They are aggregates over a table Identity owns,
     * so the statements live here even though the numbers are the report's.
     */
    public function countAll(): int
    {
        return User::query()->count();
    }

    public function countPendingVerifications(): int
    {
        return User::query()->where('verification_status', 'pending')->count();
    }

    public function countCreatedBetween(Carbon $start, Carbon $end): int
    {
        return User::query()->whereBetween('created_at', [$start, $end])->count();
    }

    public function addressDistribution(int $limit): array
    {
        return User::query()
            ->select('address', DB::raw('COUNT(*) as count'))
            ->whereNotNull('address')
            ->groupBy('address')
            ->orderByDesc('count')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['address' => (string) $row->address, 'count' => (int) $row->count])
            ->values()
            ->all();
    }

    /**
     * Stage 2 C3: the users dashboard's cards. Four COUNTS over `users`, one per card.
     */
    public function countVerifiedDrivers(): int
    {
        return User::query()->where('is_verified_driver', true)->count();
    }

    public function countPendingDrivers(): int
    {
        return User::query()
            ->where('verification_status', 'pending')
            ->whereIn('users.id', $this->photos->driverDocumentUserIdsSubquery())
            ->count();
    }

    public function countVerifiedPassengers(): int
    {
        return User::query()
            ->where('is_verified_passenger', true)
            ->where('is_verified_driver', false)
            ->count();
    }

    public function countSuspended(): int
    {
        return User::query()->bannedNow()->count();
    }

    /**
     * Stage 2 C3: the dashboard's "total drivers" card - verified drivers, plus the pending/rejected ones who
     * have submitted documents. The document predicate is Identity's own `photos` table.
     */
    public function countDrivers(): int
    {
        return User::query()
            ->where('is_verified_driver', true)
            ->orWhere(function ($q) {
                $q->whereIn('verification_status', ['pending', 'rejected'])
                    ->whereIn('users.id', $this->photos->driverDocumentUserIdsSubquery());
            })
            ->count();
    }

    /**
     * Stage 2 C3: the recent-activity feed's verification rows - users with documents whose verification moved,
     * newest first, capped at `$limit`.
     *
     * @return array<int, UserSummary>
     */
    public function recentVerificationUsers(int $limit): array
    {
        return User::query()
            ->whereIn('users.id', $this->photos->driverDocumentUserIdsSubquery())
            ->whereIn('verification_status', ['pending', 'approved', 'rejected'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(self::COLUMNS)
            ->map(fn (User $user) => $this->toSummary($user))
            ->values()
            ->all();
    }

    public function countUsersWithDriverDocumentsCreatedBetween(Carbon $start, Carbon $end): int
    {
        return User::query()
            ->whereIn('users.id', $this->photos->driverDocumentUserIdsCreatedBetweenSubquery($start, $end))
            ->count();
    }

    public function countProcessedVerifications(Carbon $start, Carbon $end): int
    {
        return User::query()
            ->whereIn('verification_status', ['approved', 'rejected'])
            ->whereIn('users.id', $this->photos->driverDocumentUserIdsSubquery())
            ->whereBetween('updated_at', [$start, $end])
            ->count();
    }

    /**
     * Stage 2 C3: the users dashboard's filtered, paginated list. The filters are the ones Console ran -
     * including the driver-document predicate, which is Identity's own `photos` table now - and the page is
     * returned as summaries so the caller renders rows without touching a model.
     */
    public function adminUserPage(
        string $type,
        string $status,
        string $date,
        ?string $search,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        $query = User::query();

        match ($type) {
            'driver' => $query->where(function ($q) {
                $q->where('is_verified_driver', true)
                    ->orWhere(function ($q2) {
                        $q2->where('verification_status', 'pending')
                            ->whereIn('users.id', $this->photos->driverDocumentUserIdsSubquery());
                    });
            }),
            'passenger' => $query
                ->where('is_verified_driver', false)
                ->whereNotIn('users.id', $this->photos->driverDocumentUserIdsSubquery()),
            default => null,
        };

        match ($status) {
            'verified' => $query->where(function ($q) {
                $q->where('is_verified_driver', true)
                    ->orWhere('is_verified_passenger', true);
            }),
            'pending' => $query->where('verification_status', 'pending'),
            'suspended' => $query->bannedNow(),
            default => null,
        };

        $cutoff = match ($date) {
            'last_30_days' => Carbon::now()->subDays(30),
            'last_3_months' => Carbon::now()->subMonths(3),
            'last_6_months' => Carbon::now()->subMonths(6),
            'last_12_months' => Carbon::now()->subMonths(12),
            default => null,
        };

        if ($cutoff) {
            $query->where('created_at', '>=', $cutoff);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (User $user) => $this->toSummary($user));
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
            createdAt: $user->created_at,
            isBannedNow: $user->isBannedNow(),
        );
    }

    /**
     * Stage 4 A3: the admin driver list. Moved verbatim from Console's `AdminDriverQueryService::getDrivers()` -
     * same columns, same driver predicate, same filters, same search, same ordering, same ONE rating read and the
     * same ONE pagination statement. What changed is who runs it: `users` is Identity's table.
     */
    public function driverListPage(
        string $filter,
        int $perPage,
        int $page,
        ?string $search,
        ?Builder $latestCommunicationNumber = null,
    ): LengthAwarePaginator {
        $query = User::query();

        // Stage 4 A3: the fragment arrives from Ride (the owner of `rides`) through the caller, which already
        // names that port. Optional, because the fragment is presentation of the phone column: without it the
        // list still runs and the phone reads as null.
        if ($latestCommunicationNumber !== null) {
            $query->addSelect(['latest_communication_number' => $latestCommunicationNumber]);
        }

        $query
            ->where(function ($q) {
                $q->where('is_verified_driver', true)
                    ->orWhere(function ($q2) {
                        $q2->whereIn('verification_status', ['pending', 'rejected'])
                            ->whereIn('users.id', $this->photos->driverDocumentUserIdsSubquery());
                    });
            });

        match ($filter) {
            'verified' => $query->where('is_verified_driver', true),
            'pending' => $query->where('verification_status', 'pending')
                ->whereIn('users.id', $this->photos->driverDocumentUserIdsSubquery()),
            'suspended' => $query->bannedNow(),
            default => null,
        };

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $query->orderByDesc('created_at');

        $paginator = $query->paginate($perPage, self::COLUMNS, 'page', $page);

        // The same batched rating read Console made, and the same one query: Identity's own reader.
        $ratings = app(UserRatingReader::class)->averageForUsers($paginator->getCollection()->pluck('id')->all());

        $summaries = $paginator->getCollection()->map(function (User $user) use ($ratings) {
            $summary = $this->toSummary($user);
            $rating = $ratings[(int) $user->id] ?? null;

            return new UserSummary(
                id: $summary->id,
                firstName: $summary->firstName,
                lastName: $summary->lastName,
                email: $summary->email,
                status: $summary->status,
                isVerifiedPassenger: $summary->isVerifiedPassenger,
                isVerifiedDriver: $summary->isVerifiedDriver,
                gender: $summary->gender,
                address: $summary->address,
                verificationStatus: $summary->verificationStatus,
                updatedAt: $summary->updatedAt,
                createdAt: $summary->createdAt,
                isBannedNow: $summary->isBannedNow,
                avgRating: $rating === null ? null : (float) $rating,
                latestCommunicationNumber: $user->latest_communication_number,
            );
        });

        return $paginator->setCollection($summaries);
    }
}
