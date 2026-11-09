<?php

use App\Enums\Status;
use App\Models\AuditLog;
use App\Models\SizePriceBand;
use App\Models\SizePriceTier;
use App\Models\TyreVariant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Covers SizePricingController / SizePriceBandRequest — gated on
 * `promotions.manage` (writes) and `promotions.view` (page), audit-logged by
 * SizePriceObserver.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function sizePricingUser(string $role): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bandPayload(array $overrides = []): array
{
    return array_merge([
        'name' => '16–17 inch',
        'rim_min' => 16,
        'rim_max' => 17,
        'status' => Status::Active->value,
        'tiers' => [
            ['quantity' => 1, 'total_price' => 16000],
            ['quantity' => 2, 'total_price' => 26000],
            ['quantity' => 3, 'total_price' => 36000],
            ['quantity' => 4, 'total_price' => 46000],
        ],
    ], $overrides);
}

test('a promotions.manage user can create a band with its tiers, audit-logged', function () {
    $this->actingAs(sizePricingUser('ecommerce'))
        ->post(route('admin.size-pricing.store'), bandPayload())
        ->assertRedirect();

    $band = SizePriceBand::query()->firstOrFail();
    expect($band->tiers->pluck('total_price', 'quantity')->all())->toBe([1 => 16000, 2 => 26000, 3 => 36000, 4 => 46000])
        ->and(AuditLog::query()->where('action', 'size_price_bands.created')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'size_price_tiers.created')->count())->toBe(4);
});

test('updating a band changes its tiers and drops removed ones', function () {
    $band = SizePriceBand::factory()->rims(16, 17)->withTiers([16000, 26000, 36000, 36000])->create();

    $this->actingAs(sizePricingUser('ecommerce'))->put(route('admin.size-pricing.update', $band), bandPayload([
        'tiers' => [['quantity' => 1, 'total_price' => 17500], ['quantity' => 2, 'total_price' => 27500]],
    ]))->assertRedirect();

    expect($band->tiers()->pluck('total_price', 'quantity')->all())->toBe([1 => 17500, 2 => 27500])
        ->and(AuditLog::query()->where('action', 'size_price_tiers.deleted')->count())->toBe(2);
});

test('deleting a band removes its tiers', function () {
    $band = SizePriceBand::factory()->withTiers([16000, 26000])->create();

    $this->actingAs(sizePricingUser('ecommerce'))->delete(route('admin.size-pricing.destroy', $band))->assertRedirect();

    expect(SizePriceBand::query()->count())->toBe(0)->and(SizePriceTier::query()->count())->toBe(0);
});

test('rejects overlapping active bands but allows an overlapping inactive one', function () {
    SizePriceBand::factory()->rims(16, 17)->withTiers([16000])->create();
    $admin = sizePricingUser('ecommerce');

    $this->actingAs($admin)->post(route('admin.size-pricing.store'), bandPayload(['rim_min' => 17, 'rim_max' => 18]))
        ->assertSessionHasErrors('rim_min');

    $this->actingAs($admin)->post(route('admin.size-pricing.store'), bandPayload(['rim_min' => 17, 'rim_max' => 18, 'status' => Status::Inactive->value]))
        ->assertSessionHasNoErrors();
});

test('rejects skipped tyre counts and a bigger order costing less', function () {
    $admin = sizePricingUser('ecommerce');

    $this->actingAs($admin)->post(route('admin.size-pricing.store'), bandPayload([
        'tiers' => [['quantity' => 1, 'total_price' => 16000], ['quantity' => 3, 'total_price' => 36000]],
    ]))->assertSessionHasErrors('tiers');

    $this->actingAs($admin)->post(route('admin.size-pricing.store'), bandPayload([
        'tiers' => [['quantity' => 1, 'total_price' => 16000], ['quantity' => 2, 'total_price' => 15000]],
    ]))->assertSessionHasErrors('tiers');

    $this->actingAs($admin)->post(route('admin.size-pricing.store'), bandPayload([
        'tiers' => array_map(fn (int $q): array => ['quantity' => $q, 'total_price' => 10000 * $q], [1, 2, 3, 4, 5]),
    ]))->assertSessionHasErrors('tiers');

    expect(SizePriceBand::query()->count())->toBe(0);
});

test('a view-only user can open the page but not change prices', function () {
    $viewer = sizePricingUser('operations'); // promotions.view only
    $band = SizePriceBand::factory()->withTiers([16000])->create();

    $this->actingAs($viewer)->get(route('admin.size-pricing.index'))->assertOk();
    $this->actingAs($viewer)->post(route('admin.size-pricing.store'), bandPayload())->assertForbidden();
    $this->actingAs($viewer)->put(route('admin.size-pricing.update', $band), bandPayload())->assertForbidden();
    $this->actingAs($viewer)->delete(route('admin.size-pricing.destroy', $band))->assertForbidden();
});

test('the page lists bands and the rim sizes no active band prices', function () {
    SizePriceBand::factory()->rims(16, 17)->withTiers([16000])->create();
    TyreVariant::factory()->create(['rim_diameter' => 17]);
    TyreVariant::factory()->create(['rim_diameter' => 22]);

    $this->actingAs(sizePricingUser('ecommerce'))->get(route('admin.size-pricing.index'))
        ->assertInertia(fn ($page) => $page
            ->component('promotions/size-pricing/index')
            ->has('bands', 1)
            ->where('unpricedRims', [22])
            ->where('maxTyres', 4));
});
