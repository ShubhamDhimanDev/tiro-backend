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
 * docs/architecture/06-open-decisions.md item 4). The client services only
 * Melbourne (VIC), so this seeds all 8 AU states/territories with only VIC
 * marked active; two Melbourne radius-type zones and one Melbourne
 * suburb_list-type zone for the outer south-east suburbs beyond the 25km
 * radius (each with real suburbs actually inside their matching radius/list,
 * for later serviceability-resolution tests); and one stock location (the
 * Melbourne depot) linked to those zones. Nothing outside Melbourne is
 * served: other areas are switched on in the admin panel when the real
 * service area grows.
 *
 * The two Melbourne radius zones (Melbourne Metro and Melbourne CBD Express)
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
            ['code' => 'NSW', 'name' => 'New South Wales', 'is_active' => false],
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

        // Suburb-list-type zone covering Melbourne's outer south-east suburbs,
        // beyond the 25km Melbourne Metro radius. origin_lat/lng kept as a
        // display centroid only — not used for resolution.
        $melbourneSouthEast = ServiceZone::query()->create([
            'name' => 'Melbourne South East',
            'state_id' => $vic->id,
            'type' => ServiceZoneType::SuburbList,
            'origin_lat' => -38.0800,
            'origin_lng' => 145.2900,
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
        // Melbourne Metro 25km radius; Geelong and Ballarat are deliberately
        // outside every zone (the client services Melbourne only), for later
        // "not serviceable" test fixtures.
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

        // Melbourne South East suburbs — explicit members of the suburb_list
        // zone, all well outside the 25km Metro radius, so they only resolve
        // through the list.
        $frankston = Suburb::query()->create([
            'name' => 'Frankston', 'state_id' => $vic->id, 'postcode' => '3199',
            'lat' => -38.1440, 'lng' => 145.1230,
        ]);
        $cranbourne = Suburb::query()->create([
            'name' => 'Cranbourne', 'state_id' => $vic->id, 'postcode' => '3977',
            'lat' => -38.1000, 'lng' => 145.2833,
        ]);
        $narreWarren = Suburb::query()->create([
            'name' => 'Narre Warren', 'state_id' => $vic->id, 'postcode' => '3805',
            'lat' => -38.0270, 'lng' => 145.3000,
        ]);
        $pakenham = Suburb::query()->create([
            'name' => 'Pakenham', 'state_id' => $vic->id, 'postcode' => '3810',
            'lat' => -38.0700, 'lng' => 145.4833,
        ]);

        $melbourneSouthEast->suburbs()->attach([$frankston->id, $cranbourne->id, $narreWarren->id, $pakenham->id]);

        $melbourneDepot = StockLocation::query()->create([
            'name' => 'Melbourne Depot',
            'address' => '1 Example Street, Melbourne VIC 3000',
            'lat' => -37.8200, 'lng' => 144.9600,
        ]);

        // Melbourne Depot backs all three Melbourne zones — demonstrates a
        // single StockLocation backing multiple ServiceZones.
        $melbourneDepot->serviceZones()->attach([$melbourneMetro->id, $melbourneCbdExpress->id, $melbourneSouthEast->id]);

        $this->command->info('Locations seeded: '.$states->count().' states (VIC active), 3 service zones, '.
            Suburb::query()->count().' suburbs, 1 stock location.');
    }
}
