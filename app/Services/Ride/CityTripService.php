<?php

namespace App\Services\Ride;

use App\Enums\RideStatus;
use App\Enums\SyrianCity;
use App\Models\Ride;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * City Trip Service
 *
 * Powers GET /api/rides/city-trips — every trip in the system that either
 * DEPARTS FROM or ARRIVES AT the authenticated user's city.
 *
 * ── How a ride is matched to a city ─────────────────────────────────────────
 *
 * Rides have no city column; pickup_address / destination_address are free-text
 * Arabic labels from Nominatim that often carry only a neighbourhood name. So a
 * ride endpoint counts as "in city X" when EITHER is true:
 *
 *   1. SPATIAL — ST_Distance_Sphere(<endpoint>_location, centroid) <= radius.
 *      Primary signal. Hits the existing spatial indexes.
 *   2. TEXT — the address string contains one of the city's aliases.
 *      Safety net for rides whose point is off but whose label names the city.
 *
 * See App\Enums\SyrianCity for the centroids, radii and aliases.
 *
 * ── Caching ─────────────────────────────────────────────────────────────────
 * NOT cached. available_seats changes on every booking, and a stale seat count
 * on a browse-and-book list directly causes failed bookings.
 */
final class CityTripService
{
    /** Ride statuses that can still be browsed and booked. */
    private const BROWSABLE_STATUSES = [
        RideStatus::ACTIVE->value,
        RideStatus::FULL->value,
    ];

    /** Whitelisted sort columns → real DB columns. */
    private const SORT_COLUMNS = [
        'departure_time' => 'rides.departure_time',
        'price'          => 'rides.price_per_seat',
        'seats'          => 'rides.available_seats',
        'created_at'     => 'rides.created_at',
        'distance'       => 'rides.distance',
    ];

    /**
     * Paginated list of trips touching $city.
     *
     * @param  SyrianCity  $city     the user's city (or the `city` override)
     * @param  array       $filters  already-validated filter bag
     * @param  int|null    $viewerId authenticated user id — used by exclude_own
     */
    public function getCityTrips(
        SyrianCity $city,
        array      $filters = [],
        ?int       $viewerId = null,
    ): LengthAwarePaginator {
        $query = $this->baseQuery();

        $this->applyCityMatch($query, $city, $filters['direction'] ?? 'both');
        $this->applyStatusAndTimeFilters($query, $filters);
        $this->applyRideFilters($query, $filters);
        $this->applyDriverFilters($query, $filters, $viewerId);
        $this->applySorting($query, $filters);

        return $query->paginate(
            perPage: (int) ($filters['per_page'] ?? 15),
            columns: ['*'],
            pageName: 'page',
            page: (int) ($filters['page'] ?? 1),
        );
    }

    /**
     * Counts per direction for the same city, so the frontend can render tab
     * badges ("From Damascus 12 / To Damascus 8").
     *
     * Uses the SAME filter bag as getCityTrips so the badges never disagree
     * with the list the user is looking at.
     *
     * OPT-IN (?with_counts=1). Each direction is one more COUNT over the same
     * ST_Distance_Sphere predicate, which MySQL cannot answer from an index —
     * so this triples the cost of the request. The direction the caller already
     * paginated is passed in via $knownDirection/$knownTotal and reused instead
     * of being re-counted.
     */
    public function getDirectionCounts(
        SyrianCity $city,
        array      $filters = [],
        ?int       $viewerId = null,
        ?string    $knownDirection = null,
        ?int       $knownTotal = null,
    ): array {
        $count = function (string $direction) use ($city, $filters, $viewerId, $knownDirection, $knownTotal): int {
            if ($direction === $knownDirection && $knownTotal !== null) {
                return $knownTotal;
            }

            $query = Ride::query();

            $this->applyCityMatch($query, $city, $direction);
            $this->applyStatusAndTimeFilters($query, $filters);
            $this->applyRideFilters($query, $filters);
            $this->applyDriverFilters($query, $filters, $viewerId);

            return $query->count();
        };

        return [
            'from' => $count('from'),
            'to'   => $count('to'),
            'both' => $count('both'),
        ];
    }

    // =========================================================================
    // QUERY BUILDING
    // =========================================================================

    /**
     * Base select. Coordinates are pulled with ST_X/ST_Y in this one query
     * instead of through the Ride model's pickup_location accessor, which
     * would fire two extra SELECTs per row (30 extra queries on a 15-row page).
     *
     * The POINT blob columns are deliberately NOT selected.
     * communication_number is also excluded — it is only exposed to a passenger
     * after booking, never on a public browse list.
     */
    private function baseQuery(): Builder
    {
        return Ride::query()
            ->select([
                'rides.id',
                'rides.driver_id',
                'rides.pickup_address',
                'rides.destination_address',
                'rides.departure_time',
                'rides.available_seats',
                'rides.price_per_seat',
                'rides.vehicle_type',
                'rides.payment_method',
                'rides.booking_type',
                'rides.status',
                'rides.distance',
                'rides.duration',
                'rides.notes',
                'rides.created_at',
            ])
            ->selectRaw('ST_Y(rides.pickup_location) as pickup_lat')
            ->selectRaw('ST_X(rides.pickup_location) as pickup_lng')
            ->selectRaw('ST_Y(rides.destination_location) as dest_lat')
            ->selectRaw('ST_X(rides.destination_location) as dest_lng')
            ->selectRaw(
                '(SELECT AVG(rating) FROM user_ratings WHERE rated_user_id = rides.driver_id) as driver_avg_rating'
            )
            ->with([
                'driver:id,first_name,last_name,gender,avatar,is_verified_driver',
                'driver.profile:user_id,profile_photo',
            ])
            // NOTE: must be select(DB::raw(...)), NOT selectRaw(...).
            // withCount() seeds the sub-query with count(*) as column 0 and then
            // truncates anything the closure APPENDS — so selectRaw is silently
            // dropped and you get a row count instead of a seat sum. select()
            // replaces the column list, so it survives.
            ->withCount(['bookings as total_booked_seats' => fn ($q) => $q
                ->whereIn('status', ['confirmed', 'completed'])
                ->select(DB::raw('COALESCE(SUM(seats), 0)')),
            ]);
    }

    /**
     * from → pickup is in the city
     * to   → destination is in the city
     * both → either one (the default the endpoint is built for)
     */
    private function applyCityMatch(Builder $query, SyrianCity $city, string $direction): void
    {
        $query->where(function (Builder $q) use ($city, $direction) {
            match ($direction) {
                'from'  => $this->matchEndpoint($q, $city, 'pickup'),
                'to'    => $this->matchEndpoint($q, $city, 'destination'),
                default => $q
                    ->where(fn (Builder $q2) => $this->matchEndpoint($q2, $city, 'pickup'))
                    ->orWhere(fn (Builder $q2) => $this->matchEndpoint($q2, $city, 'destination')),
            };
        });
    }

    /**
     * One endpoint matches the city when EITHER:
     *   - its label names the city (wins outright), OR
     *   - it is inside the city's radius AND its label does not already
     *     name a DIFFERENT governorate.
     *
     * Governorate centroids in Syria sit as little as ~60km apart, so the
     * generous radii in SyrianCity::radiusKm() (up to 70km, to cover large
     * desert governorates) routinely overlap a neighbour's centroid. Without
     * the "doesn't already name a different city" guard, e.g. any ride
     * touching القنيطرة (Quneitra) — no matter where its other endpoint is,
     * Aleppo, Al-Hasakah, anywhere — gets pulled into ريف دمشق (Rural
     * Damascus) results purely because Quneitra's centroid is ~63km from
     * Rural Damascus's, well inside its 70km radius. The address text is the
     * reliable signal here; the radius is only a fallback for points whose
     * label carries no governorate name at all.
     *
     * @param  string  $endpoint  'pickup' | 'destination'
     */
    private function matchEndpoint(Builder $query, SyrianCity $city, string $endpoint): void
    {
        $pointColumn   = "rides.{$endpoint}_location";
        $addressColumn = "rides.{$endpoint}_address";

        $query->where(function (Builder $q) use ($city, $pointColumn, $addressColumn) {
            foreach ($city->aliases() as $alias) {
                $q->orWhere($addressColumn, 'LIKE', '%' . $alias . '%');
            }

            $centroid     = $city->centroid();
            $radiusMeters = $city->radiusKm() * 1000;
            $wkt          = sprintf('POINT(%F %F)', $centroid['lng'], $centroid['lat']);

            $q->orWhere(function (Builder $q2) use ($city, $pointColumn, $addressColumn, $wkt, $radiusMeters) {
                $q2->whereRaw(
                    "ST_Distance_Sphere({$pointColumn}, ST_GeomFromText(?, 4326)) <= ?",
                    [$wkt, $radiusMeters]
                );

                $compatible = [$city, ...$city->overlappingCities()];

                foreach (SyrianCity::cases() as $other) {
                    if (in_array($other, $compatible, true)) {
                        continue;
                    }
                    foreach ($other->aliases() as $alias) {
                        $q2->where($addressColumn, 'NOT LIKE', '%' . $alias . '%');
                    }
                }
            });
        });
    }

    /**
     * Status + departure window.
     *
     * Defaults are "what a passenger can actually book": active rides that have
     * not departed yet. include_full adds rides whose seats are gone;
     * include_past drops the departure_time floor.
     */
    private function applyStatusAndTimeFilters(Builder $query, array $filters): void
    {
        $statuses = $this->boolFilter($filters, 'include_full', false)
            ? self::BROWSABLE_STATUSES
            : [RideStatus::ACTIVE->value];

        $query->whereIn('rides.status', $statuses);

        if (! $this->boolFilter($filters, 'include_past', false)) {
            $query->where('rides.departure_time', '>=', Carbon::now('Asia/Damascus'));
        }

        // Exact-day shortcut, mirrors /rides/search
        if (! empty($filters['departure_date'])) {
            $query->whereDate('rides.departure_time', Carbon::parse($filters['departure_date'])->toDateString());
            return;
        }

        if (! empty($filters['date_from'])) {
            $query->where('rides.departure_time', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('rides.departure_time', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }
    }

    /**
     * Seats, price, payment, booking type, vehicle.
     */
    private function applyRideFilters(Builder $query, array $filters): void
    {
        // Seats — the headline filter. available_seats is decremented on every
        // confirmed booking, so this is "seats still bookable right now".
        $seats = (int) ($filters['seats'] ?? 1);
        if ($seats > 0) {
            $query->where('rides.available_seats', '>=', $seats);
        }

        if (isset($filters['max_seats']) && $filters['max_seats'] !== null) {
            $query->where('rides.available_seats', '<=', (int) $filters['max_seats']);
        }

        if (isset($filters['min_price']) && $filters['min_price'] !== null) {
            $query->where('rides.price_per_seat', '>=', (float) $filters['min_price']);
        }

        if (isset($filters['max_price']) && $filters['max_price'] !== null) {
            $query->where('rides.price_per_seat', '<=', (float) $filters['max_price']);
        }

        if (! empty($filters['payment_method'])) {
            $query->where('rides.payment_method', $filters['payment_method']);
        }

        if (! empty($filters['booking_type'])) {
            $query->where('rides.booking_type', $filters['booking_type']);
        }

        if (! empty($filters['vehicle_type'])) {
            $query->where('rides.vehicle_type', 'LIKE', '%' . $filters['vehicle_type'] . '%');
        }

        // Free-text sweep over both address labels — lets the user narrow to a
        // neighbourhood inside their city ("المزة").
        if (! empty($filters['q'])) {
            $term = '%' . $filters['q'] . '%';
            $query->where(fn (Builder $q) => $q
                ->where('rides.pickup_address', 'LIKE', $term)
                ->orWhere('rides.destination_address', 'LIKE', $term));
        }
    }

    /**
     * Driver-side filters + "don't show me my own rides".
     */
    private function applyDriverFilters(Builder $query, array $filters, ?int $viewerId): void
    {
        if ($viewerId !== null && $this->boolFilter($filters, 'exclude_own', true)) {
            $query->where('rides.driver_id', '!=', $viewerId);
        }

        if (! empty($filters['driver_gender'])) {
            $query->whereHas('driver', fn ($q) => $q->where('gender', $filters['driver_gender']));
        }

        if ($this->boolFilter($filters, 'verified_driver_only', false)) {
            $query->whereHas('driver', fn ($q) => $q->where('is_verified_driver', true));
        }

        // Drivers with no ratings yet are excluded once this filter is applied,
        // because AVG over zero rows is NULL.
        if (isset($filters['min_driver_rating']) && $filters['min_driver_rating'] !== null) {
            $query->whereRaw(
                '(SELECT AVG(rating) FROM user_ratings WHERE rated_user_id = rides.driver_id) >= ?',
                [(float) $filters['min_driver_rating']]
            );
        }
    }

    private function applySorting(Builder $query, array $filters): void
    {
        $column    = self::SORT_COLUMNS[$filters['sort_by'] ?? 'departure_time'] ?? 'rides.departure_time';
        $direction = ($filters['sort_dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $query->orderBy($column, $direction);

        // Stable tiebreaker so page 2 never repeats a row from page 1
        if ($column !== 'rides.id') {
            $query->orderBy('rides.id', 'asc');
        }
    }

    /**
     * Reads a boolean-ish query param ("1", "true", "on") with a default.
     */
    private function boolFilter(array $filters, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $filters) || $filters[$key] === null || $filters[$key] === '') {
            return $default;
        }

        return filter_var($filters[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
