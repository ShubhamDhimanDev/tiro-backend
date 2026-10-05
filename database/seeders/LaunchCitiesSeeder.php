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
 * PLACEHOLDER DATA — the launch city list is not confirmed. The six cities
 * below (Sydney, Melbourne, Brisbane, Perth, Adelaide, Gold Coast) are
 * stand-ins so the storefront's location pages have something real to render.
 * The zones' radii, operating hours and suburbs for the cities LocationSeeder
 * does not already cover (Brisbane, Perth, Adelaide, Gold Coast) are invented
 * for that purpose only: there are no vans or technician shifts behind them,
 * so booking slots for these zones will be empty. Replace/edit via the admin
 * panel (Service zones: set "city") once the real list is decided.
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
        'Geelong & Surrounds' => ['Geelong', 'geelong'],
        'Sydney Inner West' => ['Sydney', 'sydney'],
    ];

    /**
     * PLACEHOLDER zones for launch cities LocationSeeder does not cover.
     *
     * @var list<array{name: string, city: string, slug: string, state: string, lat: float, lng: float, radius: int, suburbs: list<array{0: string, 1: string, 2: float, 3: float}>}>
     */
    private const PLACEHOLDER_ZONES = [
        [
            'name' => 'Brisbane Metro', 'city' => 'Brisbane', 'slug' => 'brisbane', 'state' => 'QLD',
            'lat' => -27.4698, 'lng' => 153.0251, 'radius' => 25,
            'suburbs' => [
                ['Brisbane City', '4000', -27.4698, 153.0251],
                ['Fortitude Valley', '4006', -27.4575, 153.0355],
                ['South Brisbane', '4101', -27.4810, 153.0170],
                ['Indooroopilly', '4068', -27.4996, 152.9730],
            ],
        ],
        [
            'name' => 'Gold Coast', 'city' => 'Gold Coast', 'slug' => 'gold-coast', 'state' => 'QLD',
            'lat' => -28.0167, 'lng' => 153.4000, 'radius' => 20,
            'suburbs' => [
                ['Surfers Paradise', '4217', -28.0027, 153.4300],
                ['Southport', '4215', -27.9670, 153.4130],
                ['Burleigh Heads', '4220', -28.0930, 153.4510],
            ],
        ],
        [
            'name' => 'Perth Metro', 'city' => 'Perth', 'slug' => 'perth', 'state' => 'WA',
            'lat' => -31.9505, 'lng' => 115.8605, 'radius' => 25,
            'suburbs' => [
                ['Perth', '6000', -31.9505, 115.8605],
                ['Subiaco', '6008', -31.9480, 115.8250],
                ['Fremantle', '6160', -32.0569, 115.7439],
            ],
        ],
        [
            'name' => 'Adelaide Metro', 'city' => 'Adelaide', 'slug' => 'adelaide', 'state' => 'SA',
            'lat' => -34.9285, 'lng' => 138.6007, 'radius' => 25,
            'suburbs' => [
                ['Adelaide', '5000', -34.9285, 138.6007],
                ['Glenelg', '5045', -34.9810, 138.5110],
                ['Norwood', '5067', -34.9210, 138.6310],
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

            // A placeholder city is "served" only in the sense that it now
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

        $this->command?->info('Launch cities seeded (PLACEHOLDER list: Sydney, Melbourne, Brisbane, Perth, Adelaide, Gold Coast).');
    }
}
