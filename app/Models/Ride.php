<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'departure_time' => 'datetime',
        'pickup_location' => 'array',
        'destination_location' => 'array',
        'route_geometry' => 'array',
        'status' => 'string',
        'driver_confirmed_at' => 'datetime',
        'payment_method' => 'string',
        'booking_type' => 'string',
        'finished_at' => 'datetime',
        'cash_creation_fee' => 'decimal:2',
        'cash_fee_deferred' => 'boolean',
        'chosen_route_index' => 'integer',
    ];

    // ------------------------------------------------------------------------//
    // Relationships
    // ------------------------------------------------------------------------//

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    // ------------------------------------------------------------------------//
    // Custom Accessors for pickup_location / destination_location
    // ------------------------------------------------------------------------//

    /**
     * Read the pickup coordinates.
     *
     * RV-24 (N+1 fix): this used to issue `SELECT ST_AsText(pickup_location) … WHERE id = ?` on
     * EVERY access, so serialising a page of rides fired one extra query per ride (and
     * RideResource alone touches it three times per ride). The scalar pickup_lat/pickup_lng
     * columns — already in the schema but previously never written — are populated by
     * setPickupLocationAttribute() and by the backfill migration, so the common case is a
     * plain attribute read with zero queries.
     *
     * The geometry query is KEPT as a fallback for rows written as a raw DB::raw expression
     * (seeders / factory / artisan flows bypass the mutator) and any legacy row the backfill
     * has not reached, so behaviour is identical either way. The parse below is unchanged:
     * `sscanf('POINT(%f %f)', $lng, $lat)` — first ordinate is lng, second is lat.
     */
    public function getPickupLocationAttribute(): ?array
    {
        // NOTE: the parentheses are required — PHP binds `!==` tighter than `??`, so
        // `$x['k'] ?? null !== null` would parse as a truthiness test and wrongly skip the
        // fast path for the perfectly valid coordinate 0.0 (equator / prime meridian).
        if (($this->attributes['pickup_lat'] ?? null) !== null
            && ($this->attributes['pickup_lng'] ?? null) !== null) {
            return [
                'lat' => (float) $this->attributes['pickup_lat'],
                'lng' => (float) $this->attributes['pickup_lng'],
            ];
        }

        if (! isset($this->attributes['id'])) {
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

    /**
     * Read the destination coordinates. RV-24: scalar-first with the geometry-query fallback
     * (see getPickupLocationAttribute for the full rationale).
     */
    public function getDestinationLocationAttribute(): ?array
    {
        if (($this->attributes['destination_lat'] ?? null) !== null
            && ($this->attributes['destination_lng'] ?? null) !== null) {
            return [
                'lat' => (float) $this->attributes['destination_lat'],
                'lng' => (float) $this->attributes['destination_lng'],
            ];
        }

        if (! isset($this->attributes['id'])) {
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

    // ------------------------------------------------------------------------//
    // Custom Mutators (Setters) for pickup_location / destination_location
    // ------------------------------------------------------------------------//

    public function setPickupLocationAttribute(array $coords)
    {
        if (isset($coords['lat'], $coords['lng'])) {
            $lat = (float) $coords['lat'];
            $lng = (float) $coords['lng'];
            // RV-24: also materialise the scalar lat/lng columns so reads (the accessors
            // and RideResource) do not need a per-row ST_AsText query. Derived from the SAME
            // $lat/$lng used for the geometry below, so the two can never disagree and no
            // coordinate-order (lat/lng transposition) decision is baked in. ST_Y=lat,
            // ST_X=lng for the stored POINT(lng lat) — consistent with the accessor's
            // sscanf('POINT(%f %f)', $lng, $lat) parse. Fallback: any writer that sets the
            // geometry as a raw DB::raw expression bypasses this mutator; those rows simply
            // keep the read-fallback and are backfilled by the migration.
            $this->attributes['pickup_lat'] = $lat;
            $this->attributes['pickup_lng'] = $lng;
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
            // RV-24: same as pickup — populate the scalar destination columns.
            $this->attributes['destination_lat'] = $lat;
            $this->attributes['destination_lng'] = $lng;
            $this->attributes['destination_location'] = DB::raw(
                sprintf("ST_GeomFromText('POINT(%F %F)',4326)", $lng, $lat)
            );
        }
    }

    // ------------------------------------------------------------------------//
    // Scope
    // ------------------------------------------------------------------------//

    public function scopeNearLocation(Builder $query, float $latitude, float $longitude, int $radiusKm = 10): void
    {
        $radiusMeters = $radiusKm * 1000;

        // T4-2: the old version passed ST_GeomFromText('POINT(? ?)', 4326) with
        // [$longitude, $latitude, $radiusMeters] as bindings — the '?' sat INSIDE
        // a single-quoted SQL string literal, so it was never a placeholder: no
        // binding occurred, the geometry argument was the literal text
        // "POINT(? ?)", MySQL raised an invalid-geometry error, and the extra
        // bindings collided with whereRaw's parameter counting. Build the WKT in
        // PHP (exactly what RideSearchService::getNearbyRides does correctly)
        // and bind it as ONE value. %F is a locale-independent float format, so
        // coordinates can never inject and no comma-decimal locale can corrupt
        // the WKT.
        $pointWkt = sprintf('POINT(%F %F)', $longitude, $latitude);

        $query->whereRaw(
            'ST_Distance_Sphere(pickup_location, ST_GeomFromText(?, 4326)) <= ?',
            [$pointWkt, $radiusMeters]
        );
    }
}
