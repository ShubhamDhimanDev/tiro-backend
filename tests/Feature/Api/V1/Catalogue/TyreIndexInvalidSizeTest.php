<?php

use App\Models\TyreVariant;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\LocationSeeder;

/**
 * `GET /api/v1/tyres` — dedicated cross-cutting coverage for malformed and
 * non-matching width/profile/rim_diameter combinations, complementing the
 * single-field cases already covered inline in `TyreIndexTest.php`.
 */
beforeEach(function () {
    $this->seed(LocationSeeder::class);
    $this->seed(CatalogueSeeder::class);
});

it('rejects a non-numeric width with a 422', function () {
    $response = $this->getJson('/api/v1/tyres?width=not-a-number');

    $response->assertStatus(422)->assertJsonValidationErrors('width');
});

it('rejects a negative profile with a 422', function () {
    $response = $this->getJson('/api/v1/tyres?profile=-10');

    $response->assertStatus(422)->assertJsonValidationErrors('profile');
});

it('rejects an absurdly large rim_diameter with a 422', function () {
    $response = $this->getJson('/api/v1/tyres?rim_diameter=999999999');

    $response->assertStatus(422)->assertJsonValidationErrors('rim_diameter');
});

it('rejects a negative width with a 422', function () {
    $response = $this->getJson('/api/v1/tyres?width=-205');

    $response->assertStatus(422)->assertJsonValidationErrors('width');
});

it('rejects a non-numeric rim_diameter with a 422', function () {
    $response = $this->getJson('/api/v1/tyres?rim_diameter=eighteen');

    $response->assertStatus(422)->assertJsonValidationErrors('rim_diameter');
});

it('rejects garbage across all three size fields at once, reporting every offending field', function () {
    $response = $this->getJson('/api/v1/tyres?width=abc&profile=-999&rim_diameter=99999999');

    $response->assertStatus(422)->assertJsonValidationErrors(['width', 'profile', 'rim_diameter']);
});

it('requires the rear fields when staggered=true and only front_width is present', function () {
    $response = $this->getJson('/api/v1/tyres?staggered=true&front_width=205');

    $response->assertStatus(422)->assertJsonValidationErrors([
        'front_profile', 'front_rim_diameter', 'rear_width', 'rear_profile', 'rear_rim_diameter',
    ]);
});

it('requires the missing rear field when staggered=true and only one rear field is left out', function () {
    $response = $this->getJson('/api/v1/tyres?staggered=true'
        .'&front_width=205&front_profile=55&front_rim_diameter=16'
        .'&rear_width=245&rear_profile=35');

    $response->assertStatus(422)->assertJsonValidationErrors(['rear_rim_diameter']);
});

it('returns 200 with an empty data array for a syntactically valid but non-matching size combination', function () {
    $response = $this->getJson('/api/v1/tyres?width=100&profile=20&rim_diameter=10');

    $response->assertOk();
    expect($response->json('data'))->toBe([])
        ->and(TyreVariant::query()->where('width', 100)->where('profile', 20)->where('rim_diameter', 10)->exists())
        ->toBeFalse();
});
