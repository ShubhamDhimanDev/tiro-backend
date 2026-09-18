<?php

use App\Enums\Status;
use App\Models\Brand;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\LocationSeeder;

/**
 * `GET /api/v1/tyres/{slug}` — PDP static content only, no price/stock. See
 * docs/architecture/02-api-contract.md.
 */
beforeEach(function () {
    $this->seed(LocationSeeder::class);
    $this->seed(CatalogueSeeder::class);
});

it('returns the PDP static-content shape for an active variant', function () {
    $variant = TyreVariant::query()->where('width', 205)->where('profile', 55)->where('rim_diameter', 16)->firstOrFail();

    $response = $this->getJson("/api/v1/tyres/{$variant->slug}");

    $response->assertOk()->assertJsonStructure([
        'data' => [
            'id', 'slug', 'width', 'profile', 'rim_diameter', 'load_index', 'speed_rating', 'sidewall',
            'tyre_model' => [
                'id', 'name', 'slug', 'description', 'warranty_text', 'warranty_km', 'run_flat',
                'construction', 'service_inclusions', 'images', 'category', 'tyre_type',
                'brand' => ['id', 'name', 'slug', 'logo_path', 'country_of_origin'],
            ],
        ],
    ]);

    $response->assertJsonMissingPath('data.unit_price')
        ->assertJsonMissingPath('data.stock_status')
        ->assertJsonPath('data.slug', $variant->slug);
});

it('returns 404 for a slug that does not exist', function () {
    $response = $this->getJson('/api/v1/tyres/does-not-exist');

    $response->assertNotFound();
});

it('returns 404 for a variant that is not active', function () {
    $variant = TyreVariant::query()->where('width', 205)->where('profile', 55)->where('rim_diameter', 16)->firstOrFail();
    $variant->update(['status' => Status::Inactive]);

    $response = $this->getJson("/api/v1/tyres/{$variant->slug}");

    $response->assertNotFound();
});

it('returns 404 for a variant whose parent tyre model is not active', function () {
    $draftModel = TyreModel::factory()->for(Brand::factory())->create(['status' => Status::Draft]);
    $variant = TyreVariant::factory()->for($draftModel, 'tyreModel')->create(['status' => Status::Active]);

    $response = $this->getJson("/api/v1/tyres/{$variant->slug}");

    $response->assertNotFound();
});
