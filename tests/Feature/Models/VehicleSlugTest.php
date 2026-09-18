<?php

use App\Models\Vehicle;

/**
 * `Vehicle.slug` is generated as `{make}-{model}-{series?}-{year_from}-
 * {year_to}` at creation time and stored, never derived on the fly (see
 * docs/architecture/01-data-model.md's "Vehicles & fitment" section).
 */
it('generates the slug from make/model/series/year range on creation', function () {
    $vehicle = Vehicle::factory()->create([
        'make' => 'Toyota', 'model' => 'Corolla', 'series' => 'Ascent Sport',
        'year_from' => 2019, 'year_to' => 2023,
    ]);

    expect($vehicle->slug)->toBe('toyota-corolla-ascent-sport-2019-2023');
});

it('omits a null series from the slug', function () {
    $vehicle = Vehicle::factory()->create([
        'make' => 'Holden', 'model' => 'Commodore', 'series' => null,
        'year_from' => 2010, 'year_to' => 2015,
    ]);

    expect($vehicle->slug)->toBe('holden-commodore-2010-2015');
});

it('does not regenerate the slug on update, since it is admin-editable after creation', function () {
    $vehicle = Vehicle::factory()->create();

    $vehicle->update(['slug' => 'a-custom-admin-slug']);

    expect($vehicle->fresh()->slug)->toBe('a-custom-admin-slug');
});

it('respects an explicitly provided slug at creation instead of generating one', function () {
    $vehicle = Vehicle::factory()->create(['slug' => 'explicit-slug']);

    expect($vehicle->slug)->toBe('explicit-slug');
});

it('falls back to a numeric suffix when the base slug collides, e.g. sedan vs hatch sharing every other field', function () {
    Vehicle::factory()->create([
        'make' => 'Toyota', 'model' => 'Corolla', 'series' => 'Ascent Sport', 'body_type' => 'sedan',
        'year_from' => 2019, 'year_to' => 2023,
    ]);

    // body_type isn't part of the slug format, so a hatch sharing every
    // other field collides on the base slug and must fall back.
    $hatch = Vehicle::factory()->create([
        'make' => 'Toyota', 'model' => 'Corolla', 'series' => 'Ascent Sport', 'body_type' => 'hatch',
        'year_from' => 2019, 'year_to' => 2023,
    ]);

    expect($hatch->slug)->toBe('toyota-corolla-ascent-sport-2019-2023-2');
});
