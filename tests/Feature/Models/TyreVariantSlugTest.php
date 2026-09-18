<?php

use App\Enums\TyreSidewall;
use App\Models\TyreModel;
use App\Models\TyreVariant;

/**
 * `TyreVariant.slug` is generated as `{tyre_model.slug}-{width}-{profile}-
 * r{rim_diameter}` at creation time and stored, never derived on the fly
 * (see docs/architecture/01-data-model.md and 06-open-decisions.md item 6).
 */
it('generates the base slug from the tyre model slug and size on creation', function () {
    $tyreModel = TyreModel::factory()->create(['slug' => 'bridgestone-turanza-t005']);

    $variant = TyreVariant::factory()->for($tyreModel)->create([
        'width' => 225, 'profile' => 45, 'rim_diameter' => 18,
    ]);

    expect($variant->slug)->toBe('bridgestone-turanza-t005-225-45-r18');
});

it('does not regenerate the slug on update, since it is admin-editable after creation', function () {
    $variant = TyreVariant::factory()->create();

    $variant->update(['slug' => 'a-custom-admin-slug']);

    expect($variant->fresh()->slug)->toBe('a-custom-admin-slug');
});

it('respects an explicitly provided slug at creation instead of generating one', function () {
    $tyreModel = TyreModel::factory()->create();

    $variant = TyreVariant::factory()->for($tyreModel)->create(['slug' => 'explicit-slug']);

    expect($variant->slug)->toBe('explicit-slug');
});

it('falls back to appending load_index and speed_rating when the base slug collides', function () {
    $tyreModel = TyreModel::factory()->create(['slug' => 'bridgestone-turanza-t005']);

    TyreVariant::factory()->for($tyreModel)->create([
        'width' => 205, 'profile' => 55, 'rim_diameter' => 16,
        'load_index' => '91', 'speed_rating' => 'V',
    ]);

    $second = TyreVariant::factory()->for($tyreModel)->create([
        'width' => 205, 'profile' => 55, 'rim_diameter' => 16,
        'load_index' => '95', 'speed_rating' => 'H',
        'sidewall' => TyreSidewall::ExtraLoad,
    ]);

    expect($second->slug)->toBe('bridgestone-turanza-t005-205-55-r16-95h');
});

it('falls back to a numeric suffix when even the ratings-appended slug collides', function () {
    $tyreModel = TyreModel::factory()->create(['slug' => 'bridgestone-turanza-t005']);

    // Occupies the base slug ("...-r16").
    TyreVariant::factory()->for($tyreModel)->create([
        'width' => 205, 'profile' => 55, 'rim_diameter' => 16,
        'load_index' => '91', 'speed_rating' => 'V',
    ]);

    // Same width/profile/rim as above -> base slug collides -> occupies
    // the ratings-appended slug ("...-r16-95h").
    TyreVariant::factory()->for($tyreModel)->create([
        'width' => 205, 'profile' => 55, 'rim_diameter' => 16,
        'load_index' => '95', 'speed_rating' => 'H',
        'sidewall' => TyreSidewall::ExtraLoad,
    ]);

    // Same width/profile/rim again, with a genuinely different
    // (load_index, speed_rating) pair that happens to concatenate to the
    // same "95h" string -> both the base and ratings-appended slugs
    // collide -> falls back to a numeric suffix.
    $collidingOnRatingsSlug = TyreVariant::factory()->for($tyreModel)->create([
        'width' => 205, 'profile' => 55, 'rim_diameter' => 16,
        'load_index' => '9', 'speed_rating' => '5H',
        'sidewall' => TyreSidewall::Reinforced,
    ]);

    expect($collidingOnRatingsSlug->slug)->toBe('bridgestone-turanza-t005-205-55-r16-95h-2');
});
