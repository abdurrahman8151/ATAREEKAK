<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Ride\Models\Ride;
use Illuminate\Support\Facades\DB;

/**
 * RV-34 — the single place that inserts a ride for tests.
 *
 * 13 test files each carried their own `insertRide()` with a raw INSERT (13 copies
 * of the column list, the SRID, and the geometry literals). This replaces them with
 * one builder so a schema/geometry change lands once.
 *
 * Design notes:
 *  - Default geometry is the raw-SQL convention the existing fixtures use
 *    (POINT(lat lng) Damascus -> Aleppo), NOT the model mutator's POINT(lng lat).
 *    See GeoPoint for why that difference is still unresolved and must not be
 *    silently normalised here (V1 recorded, RV-25 owns the fix).
 *  - `geometryOrder()` lets a caller opt into the other order explicitly, and
 *    `rawPickup()/rawDestination()` let a caller supply a verbatim WKT.
 *  - Writes with an explicit column list and an explicit SRID; no `$table->insert`
 *    (so a schema change surfaces here as a test failure, not a silent default).
 *  - `attributes` are applied AFTER the fixed columns so a test can override
 *    anything (status, price, timing) without a bespoke INSERT.
 */
final class RideBuilder
{
    private string $pickupWkt = 'POINT(33.5138 36.2765)';

    private string $destinationWkt = 'POINT(36.2021 37.1343)';

    /**
     * Stage 2 B1: the SCALAR coordinate columns, which production always has and this fixture did not.
     *
     * `rides` carries both the geometry columns and `pickup_lat`/`pickup_lng`/`destination_lat`/
     * `destination_lng` (added by 2025_05_22_224859 and backfilled from the geometry by
     * 2026_10_05_000001). `Ride::getPickupLocationAttribute()` reads the scalars first and falls back
     * to a PER-ROW `SELECT ST_AsText(pickup_location) ... WHERE id = ?` when they are null - so every
     * fixture inserted by this builder carried nulls and every ride response paid a query per ride.
     * Writing the scalars makes the fixture row production-shaped and the fallback disappears.
     *
     * They are the LATITUDE and LONGITUDE of the points, in that order, independent of the axis order
     * the WKT happens to use - `geometryOrder()` must not change them, and does not.
     */
    private float $pickupLat = 33.5138;

    private float $pickupLng = 36.2765;

    private float $destinationLat = 36.2021;

    private float $destinationLng = 37.1343;

    /** Stage 2 B1: opt out of the scalar columns to get the legacy geometry-only row shape. */
    private bool $withScalarCoordinates = true;

    private int $srid = 4326;

    private array $attributes = [];

    public function __construct(private readonly ?User $driver = null) {}

    public static function for(?User $driver = null): self
    {
        return new self($driver);
    }

    public static function forUserId(int $driverId): self
    {
        return (new self(null))->withAttributes(['driver_id' => $driverId]);
    }

    /** Swap the geometry axis order explicitly (never inferred). */
    public function geometryOrder(string $order): self
    {
        $this->pickupWkt = GeoPoint::make(33.5138, 36.2765, $order)->wkt();
        $this->destinationWkt = GeoPoint::make(36.2021, 37.1343, $order)->wkt();

        return $this;
    }

    /** Supply a verbatim pickup WKT (e.g. POINT(? ?) for a stored-procedure test). */
    public function rawPickup(string $wkt): self
    {
        $this->pickupWkt = $wkt;

        return $this;
    }

    public function rawDestination(string $wkt): self
    {
        $this->destinationWkt = $wkt;

        return $this;
    }

    public function srid(int $srid): self
    {
        $this->srid = $srid;

        return $this;
    }

    public function withAttributes(array $attributes): self
    {
        $this->attributes = array_merge($this->attributes, $attributes);

        return $this;
    }

    /**
     * Write the geometry only, leaving pickup_lat/pickup_lng/destination_lat/destination_lng NULL.
     *
     * Stage 2 B1: this is the pre-backfill row shape - how the seeders, the factory and any raw
     * INSERT still write it - and it is the only shape that exercises the per-row geometry fallback
     * in `Ride::getPickupLocationAttribute()`. The default is now production-shaped; a test that is
     * specifically about the fallback asks for this explicitly.
     */
    public function withoutScalarCoordinates(): self
    {
        $this->withScalarCoordinates = false;

        return $this;
    }

    /** Convenience for the very common overrides. */
    public function status(string $status): self
    {
        return $this->withAttributes(['status' => $status]);
    }

    public function price(int $pricePerSeat): self
    {
        return $this->withAttributes(['price_per_seat' => $pricePerSeat]);
    }

    public function seats(int $availableSeats): self
    {
        return $this->withAttributes(['available_seats' => $availableSeats]);
    }

    public function paymentMethod(string $method): self
    {
        return $this->withAttributes(['payment_method' => $method]);
    }

    public function departureTime($time): self
    {
        return $this->withAttributes([
            'departure_time' => $time instanceof \DateTimeInterface
                ? $time->format('Y-m-d H:i:s')
                : (string) $time,
        ]);
    }

    public function create(): Ride
    {
        $driverId = $this->attributes['driver_id']
            ?? $this->driver?->id
            ?? User::factory()->create(['is_verified_driver' => true])->id;

        $values = array_merge([
            'driver_id' => $driverId,
            'pickup_address' => 'دمشق',
            'destination_address' => 'حلب',
            'available_seats' => 3,
            'price_per_seat' => 50000,
            'payment_method' => 'e-pay',
            'booking_type' => 'direct',
            'status' => 'active',
            'distance' => 320.5,
            'duration' => 240,
            'communication_number' => '0911000000',
            'created_at' => now(),
            'updated_at' => now(),
        ], $this->attributes);

        // Stage 2 B1: production-shaped scalars. `??=` so a caller that passed its own via
        // withAttributes() wins, and the geometry columns below are untouched (the axis-order
        // question is RV-25's, not this fixture's - see the class docblock).
        if ($this->withScalarCoordinates) {
            $values['pickup_lat'] ??= $this->pickupLat;
            $values['pickup_lng'] ??= $this->pickupLng;
            $values['destination_lat'] ??= $this->destinationLat;
            $values['destination_lng'] ??= $this->destinationLng;
        }

        // DB::raw is required: the query builder would otherwise quote the whole
        // expression as a string literal and MySQL would reject it with
        // "Cannot get geometry object from data you send to the GEOMETRY field".
        $values['pickup_location'] = DB::raw($this->geometrySql($this->pickupWkt));
        $values['destination_location'] = DB::raw($this->geometrySql($this->destinationWkt));
        $values['departure_time'] = $this->normaliseDate($values['departure_time'] ?? now()->subMinutes(5));
        $values['created_at'] = $this->normaliseDate($values['created_at']);
        $values['updated_at'] = $this->normaliseDate($values['updated_at']);

        // Deliberately NOT `$ride->forceFill($values)`: Ride::setPickupLocationAttribute()
        // is typed `array $coords` and therefore REJECTS a raw geometry expression
        // (TypeError), and feeding it lat/lng would write POINT(lng lat) — the other
        // axis order, which would silently change what every migrated fixture
        // asserts. So the insert goes through the query builder, exactly like the
        // raw-SQL fixtures it replaces. Tests that specifically want production's
        // write path use ->viaModelMutators().
        $id = DB::table('rides')->insertGetId($values);

        return Ride::findOrFail($id);
    }

    /**
     * Insert through Eloquent so the model's own mutators write the geometry
     * (POINT(lng lat) — the convention V1 found in production). Use only when the
     * test is specifically about the model's write path.
     */
    public function viaModelMutators(float $pickLat = 33.5138, float $pickLng = 36.2765, float $destLat = 36.2021, float $destLng = 37.1343): Ride
    {
        $driverId = $this->attributes['driver_id']
            ?? $this->driver?->id
            ?? User::factory()->create(['is_verified_driver' => true])->id;

        $ride = new Ride;
        $ride->forceFill(array_merge([
            'driver_id' => $driverId,
            'pickup_address' => 'دمشق',
            'destination_address' => 'حلب',
            'available_seats' => 3,
            'price_per_seat' => 50000,
            'payment_method' => 'e-pay',
            'booking_type' => 'direct',
            'status' => 'active',
            'distance' => 320.5,
            'duration' => 240,
            'communication_number' => '0911000000',
        ], $this->attributes));

        $ride->pickup_location = ['lat' => $pickLat, 'lng' => $pickLng];
        $ride->destination_location = ['lat' => $destLat, 'lng' => $destLng];
        $ride->departure_time = $this->attributes['departure_time'] ?? now()->subMinutes(5);
        $ride->save();

        return $ride->refresh();
    }

    private function geometrySql(string $wkt): string
    {
        // An already-complete expression (e.g. "POINT(? ?)") is passed through as-is.
        if (str_contains($wkt, 'GeomFromText') || str_contains($wkt, '?')) {
            return $wkt;
        }

        return sprintf("ST_GeomFromText('%s', %d)", $wkt, $this->srid);
    }

    private function normaliseDate($value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }
}
