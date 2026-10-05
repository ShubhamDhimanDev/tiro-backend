<?php

use App\Enums\DurationRuleAppliesTo;
use App\Enums\TyreCategory;
use App\Models\DurationRule;
use Database\Seeders\DurationRuleSeeder;

/**
 * Every `(applies_to, key)` combination the duration formula in
 * docs/architecture/04-booking-capacity-engine.md can invoke must exist, or
 * booking creation hard-errors — see DurationRule::minutesFor().
 */
it('seeds every (applies_to, key) combination the duration formula can invoke', function () {
    $this->seed(DurationRuleSeeder::class);

    expect(DurationRule::minutesFor(DurationRuleAppliesTo::Base, 'setup_overhead'))->toBeInt();

    foreach (TyreCategory::cases() as $category) {
        expect(DurationRule::minutesFor(DurationRuleAppliesTo::TyreCategory, $category->value))->toBeInt();
    }

    expect(DurationRule::minutesFor(DurationRuleAppliesTo::TyreAddon, 'run_flat'))->toBeInt();

    foreach (['alignment', 'locking_nuts', 'staggered'] as $addon) {
        expect(DurationRule::minutesFor(DurationRuleAppliesTo::BookingAddon, $addon))->toBeInt();
    }
});

it('is idempotent — running it twice does not duplicate rows or error on the unique constraint', function () {
    $this->seed(DurationRuleSeeder::class);
    $this->seed(DurationRuleSeeder::class);

    expect(DurationRule::query()->count())->toBe(9);
});
