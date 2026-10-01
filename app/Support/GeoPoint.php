<?php

namespace App\Support;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * RV-25 — the ONE place that turns coordinates into a geometry literal.
 *
 * WHY THIS EXISTS. MySQL applies EPSG:4326 AXIS-ORDER (latitude first) to ST_GeomFromText and
 * ST_Distance_Sphere. The application used to hand-roll `sprintf("ST_GeomFromText('POINT(%F %F)',
 * 4326)", $lng, $lat)` in several places, which MySQL therefore read as POINT(lat lng): every
 * stored ride point was the transpose of its real location (Damascus->Aleppo measured 257.93 km
 * instead of the true ~309 km). Because write and read were *consistently* wrong, it hid — a
 * same-city radius query still returned 0 km. Hand-rolled sprintf calls are also exactly how the
 * convention silently drifts apart again, so every write now routes through this value object.
 *
 * THE CONVENTION, stated once: a geometry point is written POINT(latitude longitude) — LATITUDE
 * FIRST. This matches both MySQL's axis-order interpretation for SRID 4326 and the accessor parse
 * (`sscanf('POINT(%f %f)', $lat, $lng)`). Do NOT reintroduce lng-first anywhere.
 *
 * Note the naming is deliberate and symmetric with the read side:
 *   - wkt()      -> the bare literal `POINT(lat lng)`.
 *   - toSql()    -> the full `ST_GeomFromText('POINT(lat lng)', 4326)` expression, ready to embed
 *                   in a query (for parameter binding) — NOT for direct interpolation into SQL;
 *                   when writing via a model use the raw-geometry helper below.
 *   - raw()      -> a DB::raw expression for assignment to a geometry column.
 *
 * Callers pass NAMED coordinates (lat, lng) — never positional — so the axis order cannot be
 * misread at the call site.
 */
final class GeoPoint
{
    private function __construct(
        private readonly float $lat,
        private readonly float $lng,
        private readonly int $srid
    ) {}

    /** Build from NAMED coordinates. This is the only constructor. */
    public static function fromLatLng(float $lat, float $lng, int $srid = 4326): self
    {
        return new self($lat, $lng, $srid);
    }

    /** The bare WKT literal: POINT(latitude longitude) — latitude first, by convention. */
    public function wkt(): string
    {
        // %F is a locale-independent float format: coordinates can never inject SQL and a
        // comma-decimal locale cannot corrupt the WKT.
        return sprintf('POINT(%F %F)', $this->lat, $this->lng);
    }

    /** The full ST_GeomFromText expression, for embedding in a query (prefer as a bound value). */
    public function toSql(): string
    {
        return sprintf("ST_GeomFromText('%s', %d)", $this->wkt(), $this->srid);
    }

    /** A DB::raw expression for assigning to a geometry column (model attribute or insert). */
    public function raw(): Expression
    {
        return DB::raw($this->toSql());
    }

    public function lat(): float
    {
        return $this->lat;
    }

    public function lng(): float
    {
        return $this->lng;
    }

    public function srid(): int
    {
        return $this->srid;
    }
}
