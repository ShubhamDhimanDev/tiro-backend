<?php

use App\Models\TyreModel;
use App\Models\TyreVariant;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\LocationSeeder;

/**
 * `GET /api/v1/tyres?staggered=true` — dedicated cross-cutting coverage for
 * asymmetric front/rear result sets and independent `front_page`/
 * `rear_page` pagination, complementing `TyreIndexTest.php`'s single
 * both-sides-match happy path.
 */
beforeEach(function () {
    $this->seed(LocationSeeder::class);
    $this->seed(CatalogueSeeder::class);
});

it('returns front results with an empty rear side when only the front size matches', function () {
    // 205/55/16 exists in the seeded catalogue; 100/20/10 (the valid range's
    // own lower bound) does not.
    $response = $this->getJson('/api/v1/tyres?staggered=true'
        .'&front_width=205&front_profile=55&front_rim_diameter=16'
        .'&rear_width=100&rear_profile=20&rear_rim_diameter=10');

    $response->assertOk();
    expect($response->json('data.front.data'))->not->toBeEmpty()
        ->and($response->json('data.rear.data'))->toBe([]);
});

it('returns rear results with an empty front side when only the rear size matches', function () {
    $response = $this->getJson('/api/v1/tyres?staggered=true'
        .'&front_width=100&front_profile=20&front_rim_diameter=10'
        .'&rear_width=245&rear_profile=35&rear_rim_diameter=19');

    $response->assertOk();
    expect($response->json('data.front.data'))->toBe([])
        ->and($response->json('data.rear.data'))->not->toBeEmpty();
});

it('returns empty data on both sides when neither size matches', function () {
    $response = $this->getJson('/api/v1/tyres?staggered=true'
        .'&front_width=100&front_profile=20&front_rim_diameter=10'
        .'&rear_width=400&rear_profile=100&rear_rim_diameter=24');

    $response->assertOk();
    expect($response->json('data.front.data'))->toBe([])
        ->and($response->json('data.rear.data'))->toBe([]);
});

it('paginates front_page and rear_page independently even when both sides share the same size', function () {
    // 265/70/17 is seeded twice under different models (Goodyear Wrangler
    // Territory and Kumho Road Venture MT51, released 9 and 1 months ago
    // respectively) — the only size in the catalogue with two matches, so
    // per_page=1 gives each side a genuine second page to request
    // independently. Default sort is newest-released-first, so Kumho (1
    // month ago) is page 1 and Goodyear (9 months ago) is page 2.
    $kumho = TyreModel::query()->where('name', 'Road Venture MT51')->firstOrFail();
    $goodyear = TyreModel::query()->where('name', 'Wrangler Territory')->firstOrFail();
    $kumhoVariant = TyreVariant::query()->where('tyre_model_id', $kumho->id)
        ->where('width', 265)->where('profile', 70)->where('rim_diameter', 17)->firstOrFail();
    $goodyearVariant = TyreVariant::query()->where('tyre_model_id', $goodyear->id)
        ->where('width', 265)->where('profile', 70)->where('rim_diameter', 17)->firstOrFail();

    $response = $this->getJson('/api/v1/tyres?staggered=true&per_page=1'
        .'&front_width=265&front_profile=70&front_rim_diameter=17&front_page=2'
        .'&rear_width=265&rear_profile=70&rear_rim_diameter=17&rear_page=1');

    $response->assertOk();
    expect($response->json('data.front.meta.current_page'))->toBe(2)
        ->and($response->json('data.rear.meta.current_page'))->toBe(1)
        ->and($response->json('data.front.data.0.id'))->toBe($goodyearVariant->id)
        ->and($response->json('data.rear.data.0.id'))->toBe($kumhoVariant->id);
});
