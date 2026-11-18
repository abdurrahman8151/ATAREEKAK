<?php

namespace App\Modules\Console\Services;

use App\Models\User;
use App\Modules\Identity\Contracts\PhotoRepositoryInterface;
use App\Modules\Identity\Contracts\ProfileDirectory;
use App\Modules\Identity\Contracts\UserRatingReader;
use App\Modules\Ride\Contracts\RideQueryExpressions;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The driver list and driver profile queries (the paginated table and the profile view).
 *
 * Split out of AdminDriverService (behaviour unchanged). It loads the rows and shapes them
 * through the driver presenter; it does not touch the dashboard widgets. Bodies moved verbatim.
 */
final class AdminDriverQueryService
{
    public function __construct(
        private readonly AdminDriverPresenter $presenter,
    ) {}

    /**
     * Kept as the public entry point AdminDriverService (and AdminDriverServiceTest) call; the
     * formatting body now lives in the injected presenter.
     */
    public function formatDriver(User $driver): array
    {
        return $this->presenter->formatDriver($driver);
    }

    /**
     * Shape a whole page of drivers. The profile columns come from Identity in ONE query for the page -
     * the same single query the removed `profile` eager load cost - and the presenter only reads the map.
     *
     * @return array<int, array<string, mixed>>
     */
    public function formatDrivers(LengthAwarePaginator $drivers): array
    {
        $profiles = app(ProfileDirectory::class)->vehicleProfilesForUsers(
            $drivers->getCollection()->pluck('id')->all()
        );

        return $drivers->getCollection()
            ->map(fn (User $driver) => $this->presenter->formatDriver($driver, $profiles[$driver->id] ?? []))
            ->values()
            ->all();
    }

    /**
     * Returns a paginated, filterable list of drivers for the admin table.
     *
     * @param  string  $filter  all | verified | pending | suspended
     * @param  int  $perPage  1–50
     * @param  string|null  $search  Matches first name, last name, or email
     */
    public function getDrivers(
        string $filter = 'all',
        int $perPage = 10,
        int $page = 1,
        ?string $search = null
    ): LengthAwarePaginator {
        $query = User::query()
            // Stage 2 C2: Ride owns the rows, so the expression is Ride's too. It is still embedded in
            // THIS statement - the port returns a subquery, not a result set - so the endpoint's query
            // count does not move.
            ->addSelect(['latest_communication_number' => app(RideQueryExpressions::class)
                ->latestCommunicationNumberSubquery('users.id'),
            ])
            ->where(function ($q) {
                $q->where('is_verified_driver', true)
                    ->orWhere(function ($q2) {
                        $q2->whereIn('verification_status', ['pending', 'rejected'])
                            ->whereIn('users.id', app(PhotoRepositoryInterface::class)
                                ->driverDocumentUserIdsSubquery());
                    });
            });

        match ($filter) {
            'verified' => $query->where('is_verified_driver', true),
            'pending' => $query->where('verification_status', 'pending')
                ->whereIn('users.id', app(PhotoRepositoryInterface::class)->driverDocumentUserIdsSubquery()),
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

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // Stage 0.6 item 1 / Stage 2 C2: `avg_rating` used to come from `withAvg('receivedRatings ...')` -
        // one aggregate subquery. `user_ratings` is Identity's table and the kernel relation that call leaned
        // on is gone (owner ruling 2026-11-17), so the same ONE query now arrives from its batched reader and
        // the value is set on the rows under the SAME attribute name: the presenter and the pinned tests read
        // `avg_rating`, and a driver with no ratings still gets null rather than 0.
        $ratings = app(UserRatingReader::class)->averageForUsers($paginator->getCollection()->pluck('id')->all());

        foreach ($paginator->getCollection() as $driver) {
            $driver->setAttribute('avg_rating', $ratings[(int) $driver->id] ?? null);
        }

        return $paginator;
    }

    /**
     * GET /api/admin/drivers/{driverId}/profile
     *
     * Returns the full profile of a single driver including:
     *   - personal info, vehicle details, documents, rating, recent rides
     */
    public function getDriverProfile(int $driverId): array
    {
        $driver = $this->findDriverWithProfileData($driverId);

        // Stage 2 C2: `profiles` is Identity's - the five vehicle-card columns arrive from its port in ONE
        // query, replacing the `profile` eager load 1:1 (the profile endpoint's budget does not move).
        $profile = app(ProfileDirectory::class)->vehicleProfilesForUsers([$driverId])[$driverId] ?? [];

        // Stage 2 C1/C2: Identity owns `user_ratings`; the unification on ROUND(..., 2) is invisible
        // because the presenter rounds and casts before emitting.
        $ratingStats = app(UserRatingReader::class)->statsFor($driverId);

        // Stage 2 C2: one scalar, one query - Ride's COUNT, published as a number.
        $completedRides = app(RideQueryExpressions::class)->completedRidesForDriver($driverId);

        return [
            ...$this->presenter->driverProfileIdentity($driver, $profile),
            'vehicle' => $this->presenter->driverProfileVehicle($profile),
            'documents' => collect(
                app(PhotoRepositoryInterface::class)->documentsFor([$driver->id])[$driver->id] ?? []
            )->mapWithKeys(
                fn ($p) => [$p['type'] => asset('storage/'.$p['path'])]
            ),
            'rating' => $this->presenter->driverProfileRating($ratingStats),
            'stats' => [
                'completed_rides' => $completedRides,
                // RV-19 item 5: the uncapped count, not the 5-row recent collection.
                'total_rides' => (int) $driver->rides_count,
            ],
            'recent_rides' => $this->presenter->driverProfileRecentRides($driver),
        ];
    }

    /**
     * The driver row the profile view is built from: profile and the uncapped ride count.
     *
     * Stage 2 C2: the recent-rides page and the ride count used to come from the `User::rides()`
     * relation (eager-loaded with `limit(5)` for the page, `withCount('rides')` for the total). That
     * relation is gone, so the count is written as the same correlated subquery `withCount` used to
     * generate - part of the SAME statement, so the query count for this endpoint does not move - and
     * the page is queried by the presenter from Ride directly. The documents are no longer loaded with
     * the relation either: `getDriverProfile` reads them from Identity's port in one query.
     */
    private function findDriverWithProfileData(int $driverId): User
    {
        // RV-19 item 5: an uncapped scalar count, so the total is a total. It cannot share a query
        // with the limited page, which is why the two were separated in the first place.
        return User::query()
            ->select('users.*')
            ->addSelect([
                'rides_count' => app(RideQueryExpressions::class)->rideCountSubquery('users.id'),
            ])
            ->findOrFail($driverId);
    }
}
