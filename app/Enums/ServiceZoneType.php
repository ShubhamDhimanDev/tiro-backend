<?php

namespace App\Enums;

/**
 * `ServiceZone.type` — determines which fields are app-level-required
 * (origin_lat/origin_lng/radius_km for Radius) and which resolution path a
 * booking-serviceability check follows.
 */
enum ServiceZoneType: string
{
    case Radius = 'radius';
    case SuburbList = 'suburb_list';
}
