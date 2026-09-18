<?php

namespace Database\Seeders;

use App\Enums\ServiceZoneType;
use App\Enums\Status;
use App\Models\ServiceZone;
use App\Models\State;
use App\Models\StockLocation;
use App\Models\Suburb;
use Illuminate\Database\Seeder;

/**
 * Deliberately small, dev/test-only seed data for the Location &
 * serviceability domain — NOT a production launch-geography dataset (see
 * docs/architecture/06-open-decisions.md item 4). Seeds all 8 AU
 * states/territories with only 2 marked active; three radius-type zones and
 * one suburb_list-type zone (each with real suburbs actually inside their
 * matching radius/list, for later serviceability-resolution tests); and two
 * stock locations linked to those zones.
 *
 * Two of the three radius zones (Melbourne Metro and Melbourne CBD Express)
 * share the same origin on purpose — a real-world "general metro area" vs.
 * "smaller express-service area from the same depot" pattern — so every
 * suburb inside the smaller zone's radius is genuinely tied on distance
 * between the two, exercising the `ServiceZone.priority` tie-break rule
 * from docs/architecture/02-api-contract.md's "Zone resolution / overlap
 * rule" against real seeded data, not just factory-built test fixtures.
 */
class LocationSeeder extends Seeder
{
    /**
     * Seed the location & serviceability domain.
     */
    public function run(): void
    {
        $states = collect([
            ['code' => 'NSW', 'name' => 'New South Wales', 'is_active' => true],
            ['code' => 'VIC', 'name' => 'Victoria', 'is_active' => true],
            ['code' => 'QLD', 'name' => 'Queensland', 'is_active' => false],
            ['code' => 'WA', 'name' => 'Western Australia', 'is_active' => false],
            ['code' => 'SA', 'name' => 'South Australia', 'is_active' => false],
            ['code' => 'TAS', 'name' => 'Tasmania', 'is_active' => false],
            ['code' => 'ACT', 'name' => 'Australian Capital Territory', 'is_active' => false],
            ['code' => 'NT', 'name' => 'Northern Territory', 'is_active' => false],
        ])->map(fn (array $attributes) => State::query()->create([
            ...$attributes,
            'status' => Status::Active,
        ]))->keyBy('code');

        $vic = $states->get('VIC');
        $nsw = $states->get('NSW');

        // Radius-type zone around Melbourne CBD.
        $melbourneMetro = ServiceZone::query()->create([
            'name' => 'Melbourne Metro',
            'state_id' => $vic->id,
            'type' => ServiceZoneType::Radius,
            'origin_lat' => -37.8136,
            'origin_lng' => 144.9631,
            'radius_km' => 25,
            'operating_hours' => [
                'mon' => ['open' => '08:00', 'close' => '18:00'],
                'tue' => ['open' => '08:00', 'close' => '18:00'],
                'wed' => ['open' => '08:00', 'close' => '18:00'],
                'thu' => ['open' => '08:00', 'close' => '18:00'],
                'fri' => ['open' => '08:00', 'close' => '18:00'],
                'sat' => ['open' => '09:00', 'close' => '15:00'],
                'sun' => null,
            ],
            'priority' => 10,
            'status' => Status::Active,
        ]);

        // A second, smaller radius-type zone so overlap/priority resolution
        // has more than one candidate to exercise later.
        $geelong = ServiceZone::query()->create([
            'name' => 'Geelong & Surrounds',
            'state_id' => $vic->id,
            'type' => ServiceZoneType::Radius,
            'origin_lat' => -38.1499,
            'origin_lng' => 144.3617,
            'radius_km' => 15,
            'operating_hours' => [
                'mon' => ['open' => '08:00', 'close' => '17:00'],
                'tue' => ['open' => '08:00', 'close' => '17:00'],
                'wed' => ['open' => '08:00', 'close' => '17:00'],
                'thu' => ['open' => '08:00', 'close' => '17:00'],
                'fri' => ['open' => '08:00', 'close' => '17:00'],
                'sat' => null,
                'sun' => null,
            ],
            'priority' => 0,
            'status' => Status::Active,
        ]);

        // Smaller radius zone sharing Melbourne Metro's exact origin, at
        // higher priority — every suburb within 5km of Melbourne CBD is
        // therefore equidistant from both zones' origins, a genuine tie
        // that only `priority` breaks (see the class docblock).
        $melbourneCbdExpress = ServiceZone::query()->create([
            'name' => 'Melbourne CBD Express',
            'state_id' => $vic->id,
            'type' => ServiceZoneType::Radius,
            'origin_lat' => -37.8136,
            'origin_lng' => 144.9631,
            'radius_km' => 5,
            'operating_hours' => [
                'mon' => ['open' => '07:00', 'close' => '19:00'],
                'tue' => ['open' => '07:00', 'close' => '19:00'],
                'wed' => ['open' => '07:00', 'close' => '19:00'],
                'thu' => ['open' => '07:00', 'close' => '19:00'],
                'fri' => ['open' => '07:00', 'close' => '19:00'],
                'sat' => ['open' => '08:00', 'close' => '16:00'],
                'sun' => null,
            ],
            'priority' => 20,
            'status' => Status::Active,
        ]);

        // Suburb-list-type zone covering inner-west Sydney. origin_lat/lng
        // kept as a display centroid only — not used for resolution.
        $sydneyInnerWest = ServiceZone::query()->create([
            'name' => 'Sydney Inner West',
            'state_id' => $nsw->id,
            'type' => ServiceZoneType::SuburbList,
            'origin_lat' => -33.9000,
            'origin_lng' => 151.1700,
            'radius_km' => null,
            'operating_hours' => [
                'mon' => ['open' => '08:00', 'close' => '18:00'],
                'tue' => ['open' => '08:00', 'close' => '18:00'],
                'wed' => ['open' => '08:00', 'close' => '18:00'],
                'thu' => ['open' => '08:00', 'close' => '18:00'],
                'fri' => ['open' => '08:00', 'close' => '18:00'],
                'sat' => ['open' => '09:00', 'close' => '15:00'],
                'sun' => null,
            ],
            'priority' => 0,
            'status' => Status::Active,
        ]);

        // VIC suburbs — Melbourne CBD/Richmond/St Kilda sit inside the
        // Melbourne Metro 25km radius; Geelong sits inside the Geelong 15km
        // radius (it's the zone's own origin); Ballarat is deliberately
        // outside both, for later "not serviceable" test fixtures.
        $melbourneCbd = Suburb::query()->create([
            'name' => 'Melbourne', 'state_id' => $vic->id, 'postcode' => '3000',
            'lat' => -37.8136, 'lng' => 144.9631,
        ]);
        $richmond = Suburb::query()->create([
            'name' => 'Richmond', 'state_id' => $vic->id, 'postcode' => '3121',
            'lat' => -37.8230, 'lng' => 144.9986,
        ]);
        $stKilda = Suburb::query()->create([
            'name' => 'St Kilda', 'state_id' => $vic->id, 'postcode' => '3182',
            'lat' => -37.8677, 'lng' => 144.9811,
        ]);
        Suburb::query()->create([
            'name' => 'Geelong', 'state_id' => $vic->id, 'postcode' => '3220',
            'lat' => -38.1499, 'lng' => 144.3617,
        ]);
        Suburb::query()->create([
            'name' => 'Ballarat', 'state_id' => $vic->id, 'postcode' => '3350',
            'lat' => -37.5622, 'lng' => 143.8503,
        ]);

        // NSW suburbs — Newtown/Marrickville are explicit members of the
        // Sydney Inner West suburb_list zone; Bondi deliberately isn't, for
        // later "not serviceable" test fixtures.
        $newtown = Suburb::query()->create([
            'name' => 'Newtown', 'state_id' => $nsw->id, 'postcode' => '2042',
            'lat' => -33.8987, 'lng' => 151.1791,
        ]);
        $marrickville = Suburb::query()->create([
            'name' => 'Marrickville', 'state_id' => $nsw->id, 'postcode' => '2204',
            'lat' => -33.9096, 'lng' => 151.1552,
        ]);
        Suburb::query()->create([
            'name' => 'Bondi', 'state_id' => $nsw->id, 'postcode' => '2026',
            'lat' => -33.8908, 'lng' => 151.2743,
        ]);

        $sydneyInnerWest->suburbs()->attach([$newtown->id, $marrickville->id]);

        $melbourneDepot = StockLocation::query()->create([
            'name' => 'Melbourne Depot',
            'address' => '1 Example Street, Melbourne VIC 3000',
            'lat' => -37.8200, 'lng' => 144.9600,
        ]);
        $sydneyDepot = StockLocation::query()->create([
            'name' => 'Sydney Depot',
            'address' => '1 Example Street, Newtown NSW 2042',
            'lat' => -33.9000, 'lng' => 151.1700,
        ]);

        // Melbourne Depot backs all three VIC radius zones — demonstrates a
        // single StockLocation backing multiple ServiceZones.
        $melbourneDepot->serviceZones()->attach([$melbourneMetro->id, $melbourneCbdExpress->id, $geelong->id]);
        $sydneyDepot->serviceZones()->attach([$sydneyInnerWest->id]);

        $this->command->info('Locations seeded: '.$states->count().' states, 4 service zones, '.
            Suburb::query()->count().' suburbs, 2 stock locations.');
    }
}
