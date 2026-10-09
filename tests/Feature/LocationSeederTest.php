<?php

use App\Enums\ServiceZoneType;
use App\Models\ServiceZone;
use App\Models\State;
use App\Models\StockLocation;
use App\Models\Suburb;
use Database\Seeders\LocationSeeder;

/**
 * Dev/test-only seed data for the Location & serviceability domain
 * (deliberately not a production launch-geography dataset — see
 * docs/architecture/06-open-decisions.md item 4). These assertions guard
 * the specific invariants a later serviceability check needs real data
 * for: at least one radius-type and one suburb_list-type zone, each with
 * suburbs actually inside their matching radius/list.
 */
it('seeds all 8 AU states/territories with only VIC and WA marked active', function () {
    $this->seed(LocationSeeder::class);

    expect(State::query()->count())->toBe(8)
        ->and(State::query()->where('is_active', true)->pluck('code')->sort()->values()->all())->toBe(['VIC', 'WA'])
        ->and(State::query()->pluck('code')->sort()->values()->all())
        ->toBe(['ACT', 'NSW', 'NT', 'QLD', 'SA', 'TAS', 'VIC', 'WA']);
});

it('seeds at least one radius-type zone whose radius genuinely contains a seeded suburb', function () {
    $this->seed(LocationSeeder::class);

    $radiusZone = ServiceZone::query()->where('type', ServiceZoneType::Radius)->firstOrFail();

    $suburbInside = Suburb::query()->get()->first(function (Suburb $suburb) use ($radiusZone) {
        return haversineKm(
            (float) $radiusZone->origin_lat,
            (float) $radiusZone->origin_lng,
            (float) $suburb->lat,
            (float) $suburb->lng,
        ) <= (float) $radiusZone->radius_km;
    });

    expect($suburbInside)->not->toBeNull();
});

it('seeds at least one suburb_list-type zone with real suburb membership', function () {
    $this->seed(LocationSeeder::class);

    $suburbListZone = ServiceZone::query()->where('type', ServiceZoneType::SuburbList)->firstOrFail();

    expect($suburbListZone->suburbs()->count())->toBeGreaterThan(0);
});

it('seeds suburbs with real, non-zero coordinates', function () {
    $this->seed(LocationSeeder::class);

    foreach (Suburb::query()->get() as $suburb) {
        expect((float) $suburb->lat)->not->toBe(0.0)
            ->and((float) $suburb->lng)->not->toBe(0.0);
    }
});

it('links each stock location to at least one service zone', function () {
    $this->seed(LocationSeeder::class);

    expect(StockLocation::query()->count())->toBe(2);

    foreach (StockLocation::query()->get() as $stockLocation) {
        expect($stockLocation->serviceZones()->count())->toBeGreaterThan(0);
    }
});

/**
 * Great-circle distance in kilometres between two lat/lng points.
 */
function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadiusKm = 6371;

    $lat1Rad = deg2rad($lat1);
    $lat2Rad = deg2rad($lat2);
    $deltaLat = deg2rad($lat2 - $lat1);
    $deltaLng = deg2rad($lng2 - $lng1);

    $a = sin($deltaLat / 2) ** 2 + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLng / 2) ** 2;

    return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
}
