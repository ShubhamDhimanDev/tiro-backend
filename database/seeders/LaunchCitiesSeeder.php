<?php

namespace Database\Seeders;

use App\Models\ServiceZone;
use Illuminate\Database\Seeder;

/**
 * Phase 6a — the public state > city > suburb coverage tree needs each
 * served service zone to declare a city (`service_zones.city_name` /
 * `city_slug`).
 *
 * The client services only Melbourne (VIC), so the one launch city is
 * Melbourne: all three of LocationSeeder's zones roll up under it.
 *
 * Idempotent: keyed on zone name; re-running neither duplicates rows nor
 * overwrites admin edits to existing zones beyond filling in a missing city.
 * Edit or add areas via the admin panel (Service zones: set "city") once the
 * real service area grows.
 *
 * Must run after {@see LocationSeeder} (it tags that seeder's zones).
 */
class LaunchCitiesSeeder extends Seeder
{
    /**
     * LocationSeeder zones -> the city they belong to.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ZONE_CITIES = [
        'Melbourne Metro' => ['Melbourne', 'melbourne'],
        'Melbourne CBD Express' => ['Melbourne', 'melbourne'],
        'Melbourne South East' => ['Melbourne', 'melbourne'],
    ];

    public function run(): void
    {
        foreach (self::ZONE_CITIES as $zoneName => [$cityName, $citySlug]) {
            ServiceZone::query()
                ->where('name', $zoneName)
                ->whereNull('city_slug')
                ->update(['city_name' => $cityName, 'city_slug' => $citySlug]);
        }

        $this->command?->info('Launch city seeded (Melbourne VIC).');
    }
}
