<?php

use App\Enums\Status;
use App\Models\PopularSize;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\LocationSeeder;

/**
 * `GET /api/v1/tyres/popular-sizes` — see docs/architecture/02-api-contract.md.
 * Not paginated: `{ data: [{ width, profile, rim_diameter }] }`.
 */
it('returns the admin-curated popular sizes ordered by sort_order, unpaginated', function () {
    $this->seed(LocationSeeder::class);
    $this->seed(CatalogueSeeder::class);

    $response = $this->getJson('/api/v1/tyres/popular-sizes');

    $response->assertOk();
    expect($response->json())->toHaveKey('data')->not->toHaveKey('meta')->not->toHaveKey('links');

    $expectedOrder = PopularSize::query()->where('status', Status::Active)->orderBy('sort_order')
        ->get(['width', 'profile', 'rim_diameter'])
        ->map(fn (PopularSize $size) => [
            'width' => $size->width,
            'profile' => $size->profile,
            'rim_diameter' => $size->rim_diameter,
        ])->all();

    expect($response->json('data'))->toBe($expectedOrder);
});

it('excludes inactive popular sizes', function () {
    PopularSize::query()->create([
        'width' => 999, 'profile' => 99, 'rim_diameter' => 99, 'sort_order' => 0, 'status' => Status::Inactive,
    ]);

    $response = $this->getJson('/api/v1/tyres/popular-sizes');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('width'))->not->toContain(999);
});
