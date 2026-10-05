<?php

use App\Enums\CancellationFeeType;
use App\Models\CancellationPolicy;
use Database\Seeders\CancellationPolicySeeder;

/**
 * The one permissive global default row — see
 * docs/architecture/01-data-model.md's `CancellationPolicy` section and
 * docs/architecture/06-open-decisions.md item 2.
 */
it('seeds one permissive global default row', function () {
    $this->seed(CancellationPolicySeeder::class);

    $policy = CancellationPolicy::query()->whereNull('service_zone_id')->sole();

    expect($policy->notice_hours)->toBe(0)
        ->and($policy->fee_type)->toBe(CancellationFeeType::Flat)
        ->and($policy->fee_amount)->toBe(0);
});

it('is idempotent — running it twice does not create a second global row', function () {
    $this->seed(CancellationPolicySeeder::class);
    $this->seed(CancellationPolicySeeder::class);

    expect(CancellationPolicy::query()->whereNull('service_zone_id')->count())->toBe(1);
});
