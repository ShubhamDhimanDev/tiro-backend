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
it('tags the existing zones and adds the Perth placeholder so the public tree is Melbourne and Western Australia only', function () {
    $this->seed(LocationSeeder::class);
    $this->seed(LaunchCitiesSeeder::class);

    $tree = collect($this->getJson('/api/v1/locations')->assertOk()->json('data'));
    $cities = $tree
        ->flatMap(fn ($state) => collect($state['cities'])->map(fn ($city) => $city['name']))
        ->sort()->values()->all();

    expect($tree->pluck('code')->sort()->values()->all())->toBe(['VIC', 'WA'])
        ->and($cities)->toBe(['Melbourne', 'Perth']);
    expect(ServiceZone::query()->where('city_slug', 'melbourne')->count())->toBe(2)
        ->and(ServiceZone::query()->where('city_slug', 'perth')->count())->toBe(2)
        ->and(ServiceZone::query()->where('name', 'Perth Metro')->value('type'))->toBe(ServiceZoneType::Radius);
});

it('lists seeded suburbs under their city and keeps them serviceable', function () {
    $this->seed(LocationSeeder::class);
    $this->seed(LaunchCitiesSeeder::class);

    $detail = $this->getJson('/api/v1/locations/wa/perth')->assertOk()->json('data');

    expect(collect($detail['suburbs'])->pluck('name')->all())
        ->toBe(['Baldivis', 'Fremantle', 'Kwinana Town Centre', 'Mandurah', 'Perth', 'Rockingham', 'Subiaco']);
    foreach ($detail['suburbs'] as $suburb) {
        expect($this->postJson('/api/v1/serviceability', ['postcode' => $suburb['postcode']])->json('service_zone_id'))->toBe($suburb['service_zone_id']);
    }
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
