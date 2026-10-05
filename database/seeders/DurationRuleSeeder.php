<?php

namespace Database\Seeders;

use App\Enums\DurationRuleAppliesTo;
use App\Enums\Status;
use App\Enums\TyreCategory;
use App\Models\DurationRule;
use Illuminate\Database\Seeder;

/**
 * Seeds every `(applies_to, key)` combination the duration-calculation
 * formula can invoke — see docs/architecture/04-booking-capacity-engine.md's
 * "Job duration" section and docs/architecture/01-data-model.md's
 * `DurationRule` vocabulary table. A missing combination is a hard error at
 * booking-creation time, so this seeder exists to make the mechanism
 * actually usable out of the box — **minute values below are placeholders**,
 * ops retunes them via admin CRUD without a deploy once real numbers are
 * confirmed.
 *
 * Idempotent — safe to re-run (`firstOrCreate`, never a bare `create`).
 */
class DurationRuleSeeder extends Seeder
{
    public function run(): void
    {
        DurationRule::query()->firstOrCreate(
            ['applies_to' => DurationRuleAppliesTo::Base, 'key' => 'setup_overhead'],
            ['minutes' => 15, 'status' => Status::Active],
        );

        $tyreCategoryMinutes = [
            TyreCategory::Car->value => 10,
            TyreCategory::Suv->value => 12,
            TyreCategory::FourByFour->value => 15,
            TyreCategory::LightTruck->value => 18,
        ];

        foreach ($tyreCategoryMinutes as $category => $minutes) {
            DurationRule::query()->firstOrCreate(
                ['applies_to' => DurationRuleAppliesTo::TyreCategory, 'key' => $category],
                ['minutes' => $minutes, 'status' => Status::Active],
            );
        }

        DurationRule::query()->firstOrCreate(
            ['applies_to' => DurationRuleAppliesTo::TyreAddon, 'key' => 'run_flat'],
            ['minutes' => 5, 'status' => Status::Active],
        );

        $bookingAddonMinutes = [
            'alignment' => 20,
            'locking_nuts' => 5,
            'staggered' => 10,
        ];

        foreach ($bookingAddonMinutes as $key => $minutes) {
            DurationRule::query()->firstOrCreate(
                ['applies_to' => DurationRuleAppliesTo::BookingAddon, 'key' => $key],
                ['minutes' => $minutes, 'status' => Status::Active],
            );
        }

        $this->command->info('Duration rules seeded: '.DurationRule::query()->count().' rows.');
    }
}
