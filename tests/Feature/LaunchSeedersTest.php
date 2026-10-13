<?php

use App\Enums\ServiceZoneType;
use App\Models\Promotion;
use App\Models\ServiceZone;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\LaunchCitiesSeeder;
use Database\Seeders\LaunchOffersSeeder;
use Database\Seeders\LocationSeeder;

/**
 * Phase 6a placeholder seeders: launch cities and public offers.
 */
it('tags the zones with the Melbourne city so the public tree is Melbourne only', function () {
    $this->seed(LocationSeeder::class);
    $this->seed(LaunchCitiesSeeder::class);

    $tree = collect($this->getJson('/api/v1/locations')->assertOk()->json('data'));
    $cities = $tree
        ->flatMap(fn ($state) => collect($state['cities'])->map(fn ($city) => $city['name']))
        ->sort()->values()->all();

    expect($tree->pluck('code')->all())->toBe(['VIC'])
        ->and($cities)->toBe(['Melbourne']);
    expect(ServiceZone::query()->where('city_slug', 'melbourne')->count())->toBe(3)
        ->and(ServiceZone::query()->whereNull('city_slug')->count())->toBe(0)
        ->and(ServiceZone::query()->where('name', 'Melbourne South East')->value('type'))->toBe(ServiceZoneType::SuburbList);
});

it('lists seeded suburbs under their city and keeps them serviceable', function () {
    $this->seed(LocationSeeder::class);
    $this->seed(LaunchCitiesSeeder::class);

    $detail = $this->getJson('/api/v1/locations/vic/melbourne')->assertOk()->json('data');

    expect(collect($detail['suburbs'])->pluck('name')->all())
        ->toBe(['Cranbourne', 'Frankston', 'Melbourne', 'Narre Warren', 'Pakenham', 'Richmond', 'St Kilda']);
    foreach ($detail['suburbs'] as $suburb) {
        expect($this->postJson('/api/v1/serviceability', ['postcode' => $suburb['postcode']])->json('service_zone_id'))->toBe($suburb['service_zone_id']);
    }
});

it('does not serve Perth or any other WA area', function () {
    $this->seed(LocationSeeder::class);
    $this->seed(LaunchCitiesSeeder::class);

    $this->getJson('/api/v1/locations/wa/perth')->assertNotFound();
    $this->postJson('/api/v1/serviceability', ['postcode' => '6000'])->assertOk()->assertJsonPath('serviceable', false);
});

it('seeds launch cities idempotently', function () {
    $this->seed(LocationSeeder::class);
    $this->seed(LaunchCitiesSeeder::class);
    $zones = ServiceZone::query()->count();

    $this->seed(LaunchCitiesSeeder::class);

    expect(ServiceZone::query()->count())->toBe($zones);
});

it('seeds placeholder offers only when no public offer exists', function () {
    $this->seed(LocationSeeder::class);
    $this->seed(CatalogueSeeder::class);

    $this->seed(LaunchOffersSeeder::class);
    expect(Promotion::query()->where('is_public', true)->count())->toBe(3);
    expect(collect($this->getJson('/api/v1/offers')->json('data'))->pluck('slug')->sort()->values()->all())
        ->toBe(['bridgestone-4-for-3', 'michelin-20-off', 'welcome-10']);
    expect(Promotion::query()->where('is_public', true)->pluck('terms')->every(fn ($t) => ! str_contains($t, 'placeholder')))->toBeTrue();

    $this->seed(LaunchOffersSeeder::class);
    expect(Promotion::query()->count())->toBe(3);
});

it('does not seed offers over an admin-authored public offer', function () {
    Promotion::factory()->publicOffer('mine')->create();

    $this->seed(LaunchOffersSeeder::class);

    expect(Promotion::query()->count())->toBe(1);
});

it('seeded offers are code-gated, so they never change a cart price on their own', function () {
    $this->seed(LocationSeeder::class);
    $this->seed(CatalogueSeeder::class);
    $this->seed(LaunchOffersSeeder::class);

    expect(Promotion::query()->whereNull('code')->count())->toBe(0);
});

it('gives the seeded brands their tier', function () {
    $this->seed(LocationSeeder::class);
    $this->seed(CatalogueSeeder::class);

    expect($this->getJson('/api/v1/brands/michelin')->json('data.tier'))->toBe('premium')
        ->and($this->getJson('/api/v1/brands/kumho')->json('data.tier'))->toBe('mid');
});
