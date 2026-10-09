<?php

namespace Database\Seeders;

use App\Enums\ServiceZoneType;
use App\Enums\Status;
use App\Models\ServiceZone;
use App\Models\State;
use App\Models\Suburb;
use Illuminate\Database\Seeder;

/**
 * Phase 6a — the public state > city > suburb coverage tree needs each
 * served service zone to declare a city (`service_zones.city_name` /
 * `city_slug`).
 *
 * The client services only Melbourne (VIC) and Western Australia, so the two
 * launch cities are Melbourne and Perth. Perth is the WA hub: its metro zone
 * below plus LocationSeeder's "Rockingham & Mandurah" suburb-list zone both
 * roll up under the one Perth city.
 *
 * PLACEHOLDER DATA — the Perth Metro zone's radius, operating hours and
 * suburbs (LocationSeeder doesn't cover Perth's radius zone) are invented so
 * the storefront's location pages have something real to render: there are no
 * vans or technician shifts behind it, so booking slots for this zone will be
 * empty. Replace/edit via the admin panel (Service zones: set "city") once
 * the real service area is confirmed.
 *
 * Idempotent: keyed on zone name / suburb (name, state, postcode); re-running
 * neither duplicates rows nor overwrites admin edits to existing zones beyond
 * filling in a missing city.
 *
 * Must run after {@see LocationSeeder} (it tags that seeder's zones).
 */
class LaunchCitiesSeeder extends Seeder
{
    /**
     * Existing LocationSeeder zones -> the city they belong to.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const EXISTING_ZONE_CITIES = [
        'Melbourne Metro' => ['Melbourne', 'melbourne'],
        'Melbourne CBD Express' => ['Melbourne', 'melbourne'],
        'Rockingham & Mandurah' => ['Perth', 'perth'],
    ];

    /**
     * PLACEHOLDER zones for launch areas LocationSeeder does not cover.
     *
     * @var list<array{name: string, city: string, slug: string, state: string, lat: float, lng: float, radius: int, suburbs: list<array{0: string, 1: string, 2: float, 3: float}>}>
     */
    private const PLACEHOLDER_ZONES = [
        [
            'name' => 'Perth Metro', 'city' => 'Perth', 'slug' => 'perth', 'state' => 'WA',
            'lat' => -31.9505, 'lng' => 115.8605, 'radius' => 25,
            'suburbs' => [
                ['Perth', '6000', -31.9505, 115.8605],
                ['Subiaco', '6008', -31.9480, 115.8250],
                ['Fremantle', '6160', -32.0569, 115.7439],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::EXISTING_ZONE_CITIES as $zoneName => [$cityName, $citySlug]) {
            ServiceZone::query()
                ->where('name', $zoneName)
                ->whereNull('city_slug')
                ->update(['city_name' => $cityName, 'city_slug' => $citySlug]);
        }

        foreach (self::PLACEHOLDER_ZONES as $placeholder) {
            $state = State::query()->where('code', $placeholder['state'])->first();

            if ($state === null) {
                continue;
            }

            // A placeholder zone is "served" only in the sense that it now
            // appears in the public coverage tree.
            $state->update(['is_active' => true]);

            ServiceZone::query()->firstOrCreate(
                ['name' => $placeholder['name'], 'state_id' => $state->id],
                [
                    'city_name' => $placeholder['city'],
                    'city_slug' => $placeholder['slug'],
                    'type' => ServiceZoneType::Radius,
                    'origin_lat' => $placeholder['lat'],
                    'origin_lng' => $placeholder['lng'],
                    'radius_km' => $placeholder['radius'],
                    'operating_hours' => [
                        'mon' => ['open' => '08:00', 'close' => '17:00'],
                        'tue' => ['open' => '08:00', 'close' => '17:00'],
                        'wed' => ['open' => '08:00', 'close' => '17:00'],
                        'thu' => ['open' => '08:00', 'close' => '17:00'],
                        'fri' => ['open' => '08:00', 'close' => '17:00'],
                        'sat' => ['open' => '09:00', 'close' => '13:00'],
                        'sun' => null,
                    ],
                    'priority' => 0,
                    'status' => Status::Active,
                ],
            );

            foreach ($placeholder['suburbs'] as [$name, $postcode, $lat, $lng]) {
                Suburb::query()->firstOrCreate(
                    ['name' => $name, 'state_id' => $state->id, 'postcode' => $postcode],
                    ['lat' => $lat, 'lng' => $lng],
                );
            }
        }

        $this->command?->info('Launch cities seeded (Melbourne VIC + Perth WA; Perth Metro zone is PLACEHOLDER data).');
    }
}
