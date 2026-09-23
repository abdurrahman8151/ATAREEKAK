<?php

namespace App\Enums;

/**
 * Syrian City (Governorate) Enum
 *
 * The 14 governorates already used as the `users.address` allow-list in
 * SignupController and ProfileController. Centralising them here means the
 * city list, its centroids and its spelling variants live in one place.
 *
 * Why centroid + radius?
 * ----------------------
 * Rides do NOT store a city column. `pickup_address` / `destination_address`
 * are free-text Arabic labels produced by Nominatim reverse-geocoding, and
 * they frequently contain only a neighbourhood name ("المزة") with no
 * governorate in the string at all. Matching on text alone would silently
 * drop most rides.
 *
 * So a ride is considered to belong to a city when EITHER:
 *   1. its POINT column falls inside radiusKm() of the governorate centroid
 *      (uses the existing spatial indexes on pickup_location /
 *      destination_location), OR
 *   2. its address string contains one of aliases() (covers the spelling
 *      variants ادلب/إدلب, حماة/حماه, plus Latin transliterations).
 *
 * Note on دمشق / ريف دمشق: Rural Damascus geographically surrounds Damascus,
 * so a "ريف دمشق" user legitimately sees Damascus-city rides inside their
 * radius. This is intended — it is how the two governorates actually relate.
 */
enum SyrianCity: string
{
    case DAMASCUS       = 'دمشق';
    case RURAL_DAMASCUS = 'ريف دمشق';
    case DARAA          = 'درعا';
    case QUNEITRA       = 'القنيطرة';
    case AS_SUWAYDA     = 'السويداء';
    case HOMS           = 'حمص';
    case HAMA           = 'حماة';
    case LATAKIA        = 'اللاذقية';
    case TARTUS         = 'طرطوس';
    case ALEPPO         = 'حلب';
    case IDLIB          = 'ادلب';
    case AL_HASAKAH     = 'الحسكة';
    case AR_RAQQAH      = 'الرقة';
    case DEIR_EZ_ZOR    = 'دير الزور';

    /**
     * English display name — mirrors the map already used in
     * AdminReportService::getCityDistribution().
     */
    public function englishName(): string
    {
        return match ($this) {
            self::DAMASCUS       => 'Damascus',
            self::RURAL_DAMASCUS => 'Rural Damascus',
            self::DARAA          => 'Daraa',
            self::QUNEITRA       => 'Quneitra',
            self::AS_SUWAYDA     => 'As-Suwayda',
            self::HOMS           => 'Homs',
            self::HAMA           => 'Hama',
            self::LATAKIA        => 'Latakia',
            self::TARTUS         => 'Tartus',
            self::ALEPPO         => 'Aleppo',
            self::IDLIB          => 'Idlib',
            self::AL_HASAKAH     => 'Al-Hasakah',
            self::AR_RAQQAH      => 'Ar-Raqqah',
            self::DEIR_EZ_ZOR    => 'Deir ez-Zor',
        };
    }

    /**
     * Governorate centre point, ['lat' => …, 'lng' => …].
     */
    public function centroid(): array
    {
        return match ($this) {
            self::DAMASCUS       => ['lat' => 33.5138, 'lng' => 36.2765],
            self::RURAL_DAMASCUS => ['lat' => 33.5167, 'lng' => 36.3000],
            self::DARAA          => ['lat' => 32.6189, 'lng' => 36.1021],
            self::QUNEITRA       => ['lat' => 33.1256, 'lng' => 35.8236],
            self::AS_SUWAYDA     => ['lat' => 32.7094, 'lng' => 36.5661],
            self::HOMS           => ['lat' => 34.7324, 'lng' => 36.7137],
            self::HAMA           => ['lat' => 35.1318, 'lng' => 36.7578],
            self::LATAKIA        => ['lat' => 35.5196, 'lng' => 35.7915],
            self::TARTUS         => ['lat' => 34.8890, 'lng' => 35.8866],
            self::ALEPPO         => ['lat' => 36.2021, 'lng' => 37.1343],
            self::IDLIB          => ['lat' => 35.9306, 'lng' => 36.6339],
            self::AL_HASAKAH     => ['lat' => 36.5024, 'lng' => 40.7477],
            self::AR_RAQQAH      => ['lat' => 35.9594, 'lng' => 39.0136],
            self::DEIR_EZ_ZOR    => ['lat' => 35.3359, 'lng' => 40.1408],
        };
    }

    /**
     * Radius around the centroid that counts as "in this city", in kilometres.
     * Roughly follows the real governorate footprint — Damascus is a compact
     * city, Al-Hasakah and Deir ez-Zor are large desert governorates.
     */
    public function radiusKm(): int
    {
        return match ($this) {
            self::DAMASCUS       => 20,
            self::RURAL_DAMASCUS => 70,
            self::DARAA          => 45,
            self::QUNEITRA       => 35,
            self::AS_SUWAYDA     => 45,
            self::HOMS           => 60,
            self::HAMA           => 50,
            self::LATAKIA        => 40,
            self::TARTUS         => 35,
            self::ALEPPO         => 60,
            self::IDLIB          => 45,
            self::AL_HASAKAH     => 70,
            self::AR_RAQQAH      => 60,
            self::DEIR_EZ_ZOR    => 70,
        };
    }

    /**
     * Spelling variants used for LIKE matching against the free-text
     * pickup_address / destination_address labels.
     */
    public function aliases(): array
    {
        return match ($this) {
            self::DAMASCUS       => ['دمشق', 'Damascus'],
            self::RURAL_DAMASCUS => ['ريف دمشق', 'Rural Damascus', 'Rif Dimashq'],
            self::DARAA          => ['درعا', 'Daraa', 'Dara'],
            self::QUNEITRA       => ['القنيطرة', 'قنيطرة', 'Quneitra'],
            self::AS_SUWAYDA     => ['السويداء', 'سويداء', 'Suwayda', 'Sweida'],
            self::HOMS           => ['حمص', 'Homs'],
            self::HAMA           => ['حماة', 'حماه', 'Hama'],
            self::LATAKIA        => ['اللاذقية', 'لاذقية', 'Latakia'],
            self::TARTUS         => ['طرطوس', 'Tartus', 'Tartous'],
            self::ALEPPO         => ['حلب', 'Aleppo'],
            self::IDLIB          => ['ادلب', 'إدلب', 'Idlib'],
            self::AL_HASAKAH     => ['الحسكة', 'حسكة', 'Hasakah', 'Hasakeh'],
            self::AR_RAQQAH      => ['الرقة', 'رقة', 'Raqqah', 'Raqqa'],
            self::DEIR_EZ_ZOR    => ['دير الزور', 'ديرالزور', 'Deir'],
        };
    }

    /**
     * Governorates whose geography legitimately overlaps this one, so an
     * address that explicitly names one of them should NOT disqualify a
     * spatial-radius match against $this city (see CityTripService::
     * matchEndpoint()).
     *
     * Currently only دمشق / ريف دمشق: Rural Damascus geographically
     * surrounds Damascus city, so a ريف دمشق rider legitimately sees
     * دمشق-labelled rides inside their radius, and vice versa. Every other
     * pair of governorates is treated as mutually exclusive — an address
     * naming one of them means a ride does NOT belong to another city's
     * results just because it also falls inside that city's radius.
     */
    public function overlappingCities(): array
    {
        return match ($this) {
            self::DAMASCUS       => [self::RURAL_DAMASCUS],
            self::RURAL_DAMASCUS => [self::DAMASCUS],
            default              => [],
        };
    }

    /**
     * All governorate values — use as the `in:` rule for request validation.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Comma-joined values, ready to drop into a validation rule string.
     */
    public static function validationList(): string
    {
        return implode(',', self::values());
    }

    /**
     * Resolve a stored `users.address` string to a case.
     * Trims and normalises the Arabic hamza forms so 'إدلب' resolves to IDLIB.
     */
    public static function tryFromAddress(?string $address): ?self
    {
        if ($address === null || trim($address) === '') {
            return null;
        }

        $needle = trim($address);

        if ($city = self::tryFrom($needle)) {
            return $city;
        }

        // Normalise hamza / alef variants before the alias sweep
        $normalised = str_replace(['أ', 'إ', 'آ', 'ة'], ['ا', 'ا', 'ا', 'ه'], $needle);

        foreach (self::cases() as $city) {
            foreach ($city->aliases() as $alias) {
                $normalisedAlias = str_replace(['أ', 'إ', 'آ', 'ة'], ['ا', 'ا', 'ا', 'ه'], $alias);
                if (mb_strtolower($normalised) === mb_strtolower($normalisedAlias)) {
                    return $city;
                }
            }
        }

        return null;
    }
}
