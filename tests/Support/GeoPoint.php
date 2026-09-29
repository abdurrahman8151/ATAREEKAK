<?php

namespace Tests\Support;

/**
 * RV-34 — one place that turns coordinates into a geometry literal.
 *
 * WHY THIS IS EXPLICIT. The test fixtures and the model mutator currently
 * disagree about axis order:
 *
 *   - Ride::setPickupLocationAttribute(['lat'=>..,'lng'=>..]) writes
 *     POINT(lng lat) — see app/Models/Ride.php:124.
 *   - most test fixtures write raw POINT(33.5138 36.2765), i.e. POINT(lat lng).
 *
 * V1 measured which one production actually contains (true Damascus->Aleppo is
 * ~309 km; the stored values resolve to ~258 km when read as lat/lng) and
 * concluded the STORED data is transposed, but the fix is RV-25 and it has NOT
 * landed. Until it does, silently normalising a fixture's axis order would
 * change what those tests assert — which is exactly the kind of fixture edit the
 * audit froze ("Freeze all geometry-fixture edits until V1 is recorded").
 *
 * So this helper refuses to guess: the caller states the order it wants.
 */
final class GeoPoint
{
    /** Write as POINT(lat lng) — the convention the raw SQL fixtures use today. */
    public const LAT_LNG = 'lat-lng';

    /** Write as POINT(lng lat) — the convention the model mutator uses. */
    public const LNG_LAT = 'lng-lat';

    private function __construct(private readonly string $wkt) {}

    public static function make(float $first, float $second, string $order = self::LAT_LNG): self
    {
        $wkt = $order === self::LNG_LAT
            ? sprintf('POINT(%F %F)', $second, $first)
            : sprintf('POINT(%F %F)', $first, $second);

        return new self($wkt);
    }

    /**
     * Named lat/lng, written the way the MODEL writes it (POINT(lng lat)).
     * Use this when the test should agree with production's write path.
     */
    public static function fromLatLng(float $lat, float $lng): self
    {
        return new self(sprintf('POINT(%F %F)', $lng, $lat));
    }

    /** Escape hatch: an exact WKT string, for fixtures that must stay verbatim. */
    public static function raw(string $wkt): self
    {
        return new self($wkt);
    }

    public function wkt(): string
    {
        return $this->wkt;
    }

    public function toSql(int $srid = 4326): string
    {
        // "POINT(1 2)" -> "ST_GeomFromText('POINT(1 2)', 4326)"
        return sprintf('ST_GeomFromText(%s,%d)', "'".$this->wkt."'", $srid);
    }

    public function __toString(): string
    {
        return $this->wkt;
    }
}
