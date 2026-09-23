<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class Ride extends Model
{
    use HasFactory;

    protected $fillable = [
        'driver_id',
        'pickup_address',
        'destination_address',
        'status',
        'distance',
        'duration',
        'route_geometry',
        'chosen_route_index',       // ← added (was missing; fill() silently dropped it)
        'departure_time',
        'available_seats',
        'price_per_seat',
        'vehicle_type',
        'payment_method',
        'booking_type',
        'communication_number',
        'notes',
        'finished_at',
        'driver_confirmed_at',
        // ── Cash ride fee ───────────────────────────────────────────────────
        'cash_creation_fee',        // 5% of total value, recorded at creation time
        'cash_fee_deferred',        // true = added to debt (rides 1-2), false = paid immediately (rides 3+)
        // We will write to pickup_location and destination_location via the setter
    ];

    protected $casts = [
        'departure_time'      => 'datetime',
        'pickup_location'     => 'array',
        'destination_location'=> 'array',
        'route_geometry'      => 'array',
        'status'              => 'string',
        'driver_confirmed_at' => 'datetime',
        'payment_method'      => 'string',
        'booking_type'        => 'string',
        'finished_at'         => 'datetime',
        'cash_creation_fee'   => 'decimal:2',
        'cash_fee_deferred'   => 'boolean',
        'chosen_route_index'  => 'integer',
    ];

    //------------------------------------------------------------------------//
    // Relationships
    //------------------------------------------------------------------------//

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    //------------------------------------------------------------------------//
    // Custom Accessors for pickup_location / destination_location
    //------------------------------------------------------------------------//

    public function getPickupLocationAttribute(): ?array
    {
        if (!isset($this->attributes['id'])) {
            return null;
        }

        $row = DB::selectOne(
            'SELECT ST_AsText(`pickup_location`) AS wkt FROM `rides` WHERE `id` = ?',
            [$this->attributes['id']]
        );

        if (! $row || ! isset($row->wkt)) {
            return null;
        }

        sscanf($row->wkt, 'POINT(%f %f)', $lng, $lat);
        return ['lat' => $lat, 'lng' => $lng];
    }

    public function getDestinationLocationAttribute(): ?array
    {
        if (!isset($this->attributes['id'])) {
            return null;
        }

        $row = DB::selectOne(
            'SELECT ST_AsText(`destination_location`) AS wkt FROM `rides` WHERE `id` = ?',
            [$this->attributes['id']]
        );

        if (! $row || ! isset($row->wkt)) {
            return null;
        }

        sscanf($row->wkt, 'POINT(%f %f)', $lng, $lat);
        return ['lat' => $lat, 'lng' => $lng];
    }

    //------------------------------------------------------------------------//
    // Custom Mutators (Setters) for pickup_location / destination_location
    //------------------------------------------------------------------------//

    public function setPickupLocationAttribute(array $coords)
    {
        if (isset($coords['lat'], $coords['lng'])) {
            $lat = (float) $coords['lat'];
            $lng = (float) $coords['lng'];
            $this->attributes['pickup_location'] = DB::raw(
                sprintf("ST_GeomFromText('POINT(%F %F)',4326)", $lng, $lat)
            );
        }
    }

    public function setDestinationLocationAttribute(array $coords)
    {
        if (isset($coords['lat'], $coords['lng'])) {
            $lat = (float) $coords['lat'];
            $lng = (float) $coords['lng'];
            $this->attributes['destination_location'] = DB::raw(
                sprintf("ST_GeomFromText('POINT(%F %F)',4326)", $lng, $lat)
            );
        }
    }

    //------------------------------------------------------------------------//
    // Route geometry helpers
    //------------------------------------------------------------------------//

    /**
     * The stored route polyline reduced to its first and last point.
     *
     * A real ORS route is thousands of [lng, lat] pairs, which dominates the
     * payload of any endpoint that returns one row per ride. List endpoints use
     * this instead; GET /rides/{id} still carries the full polyline so the map
     * has a line to draw. The full geometry stays in the column untouched —
     * corridor search (RideSearchService) reads it straight from SQL.
     *
     * Returns the value unchanged when there is nothing to trim, so a null or
     * already-minimal geometry passes through as-is.
     */
    public function routeEndpoints(): ?array
    {
        $coordinates = $this->route_geometry['coordinates'] ?? null;

        if (! is_array($coordinates) || count($coordinates) < 3) {
            return $this->route_geometry;
        }

        return [
            'type'        => 'LineString',
            'coordinates' => [
                $coordinates[array_key_first($coordinates)],
                $coordinates[array_key_last($coordinates)],
            ],
        ];
    }

    //------------------------------------------------------------------------//
    // Scope
    //------------------------------------------------------------------------//

    public function scopeNearLocation(Builder $query, float $latitude, float $longitude, int $radiusKm = 10): void
    {
        $radiusMeters = $radiusKm * 1000;
        $query->whereRaw(
            "ST_Distance_Sphere( `pickup_location`, ST_GeomFromText('POINT(? ?)', 4326) ) <= ?",
            [$longitude, $latitude, $radiusMeters]
        );
    }
}
