<?php

namespace App\Services\Location;

use App\Models\ServiceZone;

/**
 * Stateless geo-math helpers shared by radius-type {@see ServiceZone}
 * resolution and any future distance-based feature.
 */
final class Geo
{
    private const EARTH_RADIUS_KM = 6371.0;

    /**
     * Great-circle distance in kilometres between two lat/lng points.
     */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) ** 2 + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
