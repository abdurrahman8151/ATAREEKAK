<?php

namespace App\Services\Admin;

use App\Models\Booking;
use App\Models\Profile;
use App\Models\Ride;
use App\Models\User;
use App\Models\UserRating;
use App\Services\Admin\Concerns\AdminDriverPresenting;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The driver list and driver profile queries (the paginated table and the profile view).
 *
 * Split out of AdminDriverService (behaviour unchanged). It loads the rows and shapes them
 * through AdminDriverPresenting; it does not touch the dashboard widgets. Bodies moved verbatim.
 */
final class AdminDriverQueryService
{
    use AdminDriverPresenting;

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
        $query = User::with([
            'profile:user_id,profile_photo,type_of_car,color_of_car',
            'photos',
        ])
            ->where(function ($q) {
                $q->where('is_verified_driver', true)
                    ->orWhere(function ($q2) {
                        $q2->whereIn('verification_status', ['pending', 'rejected'])
                            ->whereHas('photos', fn ($p) => $p->whereIn('type', ['license', 'mechanic_card']));
                    });
            });

        match ($filter) {
            'verified' => $query->where('is_verified_driver', true),
            'pending' => $query->where('verification_status', 'pending')
                ->whereHas('photos', fn ($p) => $p->whereIn('type', ['license', 'mechanic_card'])),
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

        $paginator->getCollection()->transform(function (User $driver) {
            $driver->avg_rating = UserRating::where('rated_user_id', $driver->id)->avg('rating');

            return $driver;
        });

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

        $ratingStats = UserRating::where('rated_user_id', $driverId)
            ->selectRaw('COUNT(*) as total, AVG(rating) as average')
            ->first();

        $completedRides = Ride::where('driver_id', $driverId)
            ->where('status', 'finished')
            ->count();

        return [
            ...$this->driverProfileIdentity($driver),
            'vehicle' => $this->driverProfileVehicle($driver->profile),
            'documents' => $driver->photos->mapWithKeys(
                fn ($p) => [$p->type => asset('storage/'.$p->path)]
            ),
            'rating' => $this->driverProfileRating($ratingStats),
            'stats' => [
                'completed_rides' => $completedRides,
                // RV-19 item 5: the uncapped count, not the 5-row recent collection.
                'total_rides' => (int) $driver->rides_count,
            ],
            'recent_rides' => $this->driverProfileRecentRides($driver),
        ];
    }

    /**
     * The driver row the profile view is built from: profile, photos, the 5 most recent rides
     * with their booking counts, and the uncapped ride count.
     */
    private function findDriverWithProfileData(int $driverId): User
    {
        return User::with([
            'profile',
            'photos',
            // Only the RECENT rides are limited, for the `recent_rides` list below. `total_rides`
            // used to be `$driver->rides->count()` off THIS relation, so it counted the capped
            // collection: any driver with more than 5 rides reported exactly 5, and one with fewer
            // reported a number that looked like a total but was a page size.
            'rides' => fn ($q) => $q
                ->withCount('bookings')
                ->orderByDesc('created_at')
                ->limit(5),
            // RV-19 item 5: an uncapped scalar count, so the total is a total. Kept separate from
            // the limited relation on purpose - sharing one relation cannot serve both a page of
            // recent rows and a lifetime count.
        ])
            ->withCount('rides')
            ->findOrFail($driverId);
    }
}
