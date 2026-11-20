<?php

namespace App\Modules\Console\Services;

use App\Models\User;
use App\Modules\Identity\Contracts\PhotoRepositoryInterface;
use App\Modules\Identity\Contracts\ProfileDirectory;
use App\Modules\Identity\Contracts\UserDirectory;
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

        // Stage 4 A3: the rows are Identity's summaries now, so the presenter shapes those.
        return $drivers->getCollection()
            // Stage 4 A3: the rows are Identity's summaries; Console reads their properties and never names
            // the DTO class (rule A: a module may name another module's Contracts only).
            ->map(fn ($driver) => $this->presenter->formatDriverSummary($driver, $profiles[$driver->id] ?? []))
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
        // Stage 4 A3: `users` is Identity's table, so the whole statement - the driver predicate, the filters,
        // the search, the ordering and the batched rating read - runs there and comes back as summaries. Same
        // one pagination statement, same one rating read, same row order; the caller renders the DTOs.
        return app(UserDirectory::class)->driverListPage(
            $filter,
            $perPage,
            $page,
            $search,
            // Built by the module that owns `rides`, embedded by the module that owns `users`: one statement,
            // one owner per table, no new port reference on either side.
            app(RideQueryExpressions::class)->latestCommunicationNumberSubquery('users.id'),
        );
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
