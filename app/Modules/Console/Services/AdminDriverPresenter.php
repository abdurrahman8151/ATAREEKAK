<?php

namespace App\Modules\Console\Services;

use App\Domain\ValueObjects\Money;
use App\Models\User;
use App\Modules\Ride\Contracts\DriverRideReads;
use Carbon\Carbon;

/**
 * How the admin driver screens are shaped: a driver row, a driver profile, the driver dashboard
 * blocks, plus the small resolvers those shapes share (status, phone, human time).
 *
 * Split out of AdminDriverService (behaviour unchanged). It owns no state and runs no queries of
 * its own beyond the resolvers, so every class that renders a driver screen can use it. Bodies
 * moved verbatim, so the response keys, the Arabic labels and the fallbacks are exactly as they
 * were.
 */
final class AdminDriverPresenter
{
    /**
     * Shape a single User (driver) model into the table-row array
     * that the controller returns to the frontend.
     */
    public function formatDriver(User $driver): array
    {
        // RV-37 / un9: this is called once per driver in list/format paths; the profile is read
        // unconditionally below, so load it once explicitly instead of per driver (N+1).
        $driver->loadMissing('profile');
        $profile = $driver->profile;

        $vehicleLabel = null;
        if ($profile?->type_of_car) {
            $vehicleLabel = $profile->type_of_car;
            if ($profile->color_of_car) {
                $vehicleLabel .= ' | '.$profile->color_of_car;
            }
        }

        return [
            'id' => $driver->id,
            'driver_ref' => '#DR-'.$driver->id,
            'full_name' => trim("{$driver->first_name} {$driver->last_name}"),
            'profile_photo' => $profile?->profile_photo
                ? asset('storage/'.$profile->profile_photo)
                : null,
            'phone' => $this->driverPhone($driver),
            'vehicle' => $vehicleLabel,
            'status' => $this->resolveDriverStatus($driver),
            'avg_rating' => isset($driver->avg_rating) && $driver->avg_rating !== null
                ? round((float) $driver->avg_rating, 1)
                : null,
            'is_verified_driver' => (bool) $driver->is_verified_driver,
            'verification_status' => $driver->verification_status,
            'joined_at' => $driver->created_at->toIso8601String(),
        ];
    }

    /** Identity, contact and verification keys of the driver profile payload, in their order. */
    public function driverProfileIdentity(User $driver): array
    {
        $profile = $driver->profile;

        return [
            'id' => $driver->id,
            'driver_ref' => '#DR-'.$driver->id,
            'full_name' => trim("{$driver->first_name} {$driver->last_name}"),
            'email' => $driver->email,
            'profile_photo' => $profile?->profile_photo
                ? asset('storage/'.$profile->profile_photo)
                : null,
            'phone' => $this->resolveDriverPhone($driver->id),
            'address' => $driver->address,
            'gender' => $driver->gender,
            'verification_status' => $driver->verification_status,
            'is_verified_driver' => (bool) $driver->is_verified_driver,
            'status' => $this->resolveDriverStatus($driver),
            'joined_at' => $driver->created_at->toIso8601String(),
        ];
    }

    /**
     * Stage 2 C2: the parameter is the driver's profile row, but the type hint is GONE - `profiles` is
     * Identity's table, and this screen only READS fields off a value it was handed. The untyped
     * parameter is this codebase's established way to hold another module's model (the same choice as
     * Money's ride parameters, sec 322); every `$profile?->...` read below is untouched.
     */
    public function driverProfileVehicle($profile): array
    {
        return [
            'type' => $profile?->type_of_car,
            'color' => $profile?->color_of_car,
            'seats' => $profile?->number_of_seats,
            'photo' => $profile?->car_pic
                ? asset('storage/'.$profile->car_pic)
                : null,
        ];
    }

    public function driverProfileRating($ratingStats): array
    {
        return [
            'average' => $ratingStats->average
                ? round((float) $ratingStats->average, 2)
                : null,
            'total_ratings' => (int) $ratingStats->total,
        ];
    }

    /**
     * Stage 2 C2: this used to read `$driver->rides`, the relation `User::rides()` provided and the query
     * service eager-loaded with `limit(5)`; after that relation went it queried Ride directly. Both are
     * Ride's, so the query AND the row formatting moved behind the module's `DriverRideReads` port - the
     * same five rows, same ordering, same `bookings_count`, same limit, one statement either way.
     */
    public function driverProfileRecentRides(User $driver): array
    {
        return app(DriverRideReads::class)->recentRidesForDriver($driver->id);
    }

    /**
     * Assemble the driver dashboard payload. Money is rounded once, here (R2 sec 117).
     */
    public function formatDriverDashboard(
        User $driver,
        $profile,
        $ratingStats,
        array $stats,
        array $money,
        $recentRides,
        $favoriteDestination,
        $documents,
    ): array {
        [$ledgerEarnings, $estimatedGross] = $money;

        return [
            ...$this->dashboardIdentityBlock($driver, $profile, $ratingStats),

            'stats' => [
                'total_rides' => $stats['total_rides'],
                'completed_rides' => $stats['completed_rides'],
                'cancelled_rides' => $stats['cancelled_rides'],
                'cancel_rate' => $stats['cancel_rate'],                        // e.g. 2.4 (%)
                // AUTHORITATIVE: real settled money paid to this driver for rides.
                // `R2 sec 117`: money figures are rounded once, in `Money`.
                'ledger_earnings' => Money::from((float) $ledgerEarnings)->amount(),
                // A projection off CURRENT ride prices, not a payout.
                'estimated_gross' => Money::from((float) $estimatedGross)->amount(),
                // ALIAS of `ledger_earnings`, kept so the admin front-end does not break (D5 = C).
                'total_earnings' => Money::from((float) $ledgerEarnings)->amount(),
            ],

            'vehicle' => [
                // Stage 2 C2: this block's five columns arrive as an ARRAY from Identity's port (the
                // presenter used to take the `Profile` model); the labels and URLs stay here.
                'type' => $profile['type_of_car'] ?? null,
                'color' => $profile['color_of_car'] ?? null,
                'seats' => $profile['number_of_seats'] ?? null,
                'photo_url' => ($profile['car_pic'] ?? null)
                    ? asset('storage/'.$profile['car_pic'])
                    : null,
            ],

            'documents' => $documents,

            // Stage 2 C2: the rows arrive from Ride's `DriverRideReads` port already shaped, so this block
            // passes them through instead of reading model attributes. `price_per_seat` is still cast
            // here, at the one place that decides the money format.
            'recent_rides' => collect($recentRides)->map(fn ($ride) => [
                'id' => $ride['id'],
                'status' => $ride['status'],
                'source' => $ride['source'],
                'destination' => $ride['destination'],
                'price_per_seat' => (float) $ride['price_per_seat'],
                'date' => $ride['date'],
            ])->values(),

            // Stage 2 C2: the same port produces the two published keys, or null.
            'favorite_destination' => $favoriteDestination,
        ];
    }

    /**
     * Identity and rating keys of the dashboard payload, in their original order.
     */
    public function dashboardIdentityBlock(User $driver, $profile, $ratingStats): array
    {
        return [
            'id' => $driver->id,
            'driver_ref' => '#DR-'.$driver->id,
            'full_name' => trim("{$driver->first_name} {$driver->last_name}"),
            'email' => $driver->email,
            'phone' => $this->resolveDriverPhone($driver->id),
            'gender' => $driver->gender,
            'address' => $driver->address,
            'joined_at' => $driver->created_at->toIso8601String(),
            'status' => $this->resolveDriverStatus($driver),
            'is_verified' => (bool) $driver->is_verified_driver,
            'verification_status' => $driver->verification_status,

            'profile_photo' => ($profile['profile_photo'] ?? null)
                ? asset('storage/'.$profile['profile_photo'])
                : null,

            'rating' => [
                'average' => $ratingStats->average ?? 0,
                'total_ratings' => (int) ($ratingStats->total ?? 0),
            ],
        ];
    }

    public function resolveDriverStatus(User $driver): string
    {
        // RV-42a: "suspended" means BANNED (status -1) - see `User::scopeBannedNow()`.
        // It was `status == 0`, which is LOGGED_OUT, so every driver who was simply
        // signed out, or who had been unbanned, read as suspended.
        if ($driver->isBannedNow()) {
            return 'suspended';
        }
        if ($driver->is_verified_driver) {
            return 'verified';
        }
        if ($driver->verification_status === 'pending') {
            return 'pending';
        }
        if ($driver->verification_status === 'rejected') {
            return 'rejected';
        }

        return 'unverified';
    }

    /**
     * The driver's contact number, without a per-driver query when the caller already materialised
     * it (stage 0.6 item 1).
     *
     * `AdminDriverQueryService::getDrivers()` adds `latest_communication_number` to the list query,
     * so the admin table answers from that attribute. Every other caller (the single-driver profile)
     * has no such attribute and keeps the direct query below - same value either way, and the
     * attribute is checked with array_key_exists so a NULL number does not trigger a redundant
     * lookup per row.
     */
    private function driverPhone(User $driver): ?string
    {
        if (array_key_exists('latest_communication_number', $driver->getAttributes())) {
            return $driver->latest_communication_number;
        }

        return $this->resolveDriverPhone($driver->id);
    }

    public function resolveDriverPhone(int $driverId): ?string
    {
        // Stage 2 C2: the ride row is Ride's, so the lookup is too (one query, unchanged).
        return app(DriverRideReads::class)->latestCommunicationNumberForDriver($driverId);
    }

    public function humanTime(Carbon $time): string
    {
        $diffMins = $time->diffInMinutes(now());

        if ($diffMins < 60) {
            return $diffMins.' mins ago';
        }
        if ($diffMins < 1440) {
            return $time->diffInHours(now()).' hours ago';
        }

        return $time->format('d M Y');
    }
}
