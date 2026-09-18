<?php

use App\Enums\Status;
use App\Models\Brand;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\LocationSeeder;

/**
 * `GET /api/v1/brands` — see docs/architecture/02-api-contract.md.
 */
it('returns active brands with the documented fields, sorted by name', function () {
    $this->seed(LocationSeeder::class);
    $this->seed(CatalogueSeeder::class);

    $response = $this->getJson('/api/v1/brands');

    $response->assertOk()->assertJsonStructure([
        'data' => ['*' => ['id', 'name', 'slug', 'logo_path', 'country_of_origin']],
    ]);

    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toBe(collect($names)->sort()->values()->all());
    expect($response->json('data'))->toHaveCount(Brand::query()->count());
});

it('excludes inactive brands', function () {
    $inactiveBrand = Brand::factory()->create(['status' => Status::Inactive, 'name' => 'Zzz Inactive Brand']);

    $response = $this->getJson('/api/v1/brands');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->not->toContain($inactiveBrand->id);
});
