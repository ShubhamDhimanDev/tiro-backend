<?php

use App\Enums\CancellationFeeType;
use App\Enums\Status;
use App\Models\AuditLog;
use App\Models\PriceRule;
use App\Models\ServiceZone;
use App\Models\User;
use App\Observers\PriceRuleObserver;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Covers App\Http\Controllers\Admin\Promotions\PriceRuleController and
 * App\Http\Requests\Admin\Promotions\PriceRuleRequest — gated on
 * `promotions.manage`, not `locations.manage`, per
 * docs/architecture/05-promotions-pricing.md's RBAC decision. `AuditLog`
 * wiring is via {@see PriceRuleObserver}, asserted here too.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function priceRulesManager(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('ecommerce'); // promotions.manage, not locations.manage

    return $user;
}

function validPriceRulePayload(array $overrides = []): array
{
    return array_merge([
        'service_zone_id' => ServiceZone::factory()->create()->id,
        'fee_type' => CancellationFeeType::Flat->value,
        'fee_amount' => 1500,
        'fee_percent' => null,
        'status' => Status::Inactive->value,
    ], $overrides);
}

test('a promotions.manage user can create a price rule and it is audit-logged', function () {
    $admin = priceRulesManager();

    $response = $this->actingAs($admin)->post(route('admin.price-rules.store'), validPriceRulePayload());

    $response->assertRedirect();
    $this->assertDatabaseHas('price_rules', ['fee_amount' => 1500]);
    expect(AuditLog::query()->where('action', 'price_rules.created')->where('auditable_type', PriceRule::class)->count())->toBe(1);
});

test('a promotions.manage user can update a price rule and it is audit-logged', function () {
    $admin = priceRulesManager();
    $rule = PriceRule::factory()->create(['fee_amount' => 500, 'status' => Status::Inactive]);

    $response = $this->actingAs($admin)->put(route('admin.price-rules.update', $rule), validPriceRulePayload([
        'service_zone_id' => $rule->service_zone_id,
        'fee_amount' => 2500,
    ]));

    $response->assertRedirect();
    expect($rule->refresh()->fee_amount)->toBe(2500);
    expect(AuditLog::query()->where('action', 'price_rules.updated')->where('auditable_type', PriceRule::class)->count())->toBe(1);
});

test('fee_amount is required with a user-visible message when fee_type is flat', function () {
    $admin = priceRulesManager();

    $response = $this->actingAs($admin)->post(route('admin.price-rules.store'), validPriceRulePayload([
        'fee_type' => CancellationFeeType::Flat->value,
        'fee_amount' => null,
    ]));

    $response->assertSessionHasErrors(['fee_amount' => 'A fee amount is required for flat fees.']);
});

test('fee_percent is required with a user-visible message when fee_type is percent', function () {
    $admin = priceRulesManager();

    $response = $this->actingAs($admin)->post(route('admin.price-rules.store'), validPriceRulePayload([
        'fee_type' => CancellationFeeType::Percent->value,
        'fee_amount' => null,
        'fee_percent' => null,
    ]));

    $response->assertSessionHasErrors(['fee_percent' => 'A fee percentage is required for percent fees.']);
});

test('creating an active price rule while one already exists for that zone is rejected', function () {
    $admin = priceRulesManager();
    $zone = ServiceZone::factory()->create();
    PriceRule::factory()->create(['service_zone_id' => $zone->id, 'status' => Status::Active]);

    $response = $this->actingAs($admin)->post(route('admin.price-rules.store'), validPriceRulePayload([
        'service_zone_id' => $zone->id,
        'status' => Status::Active->value,
    ]));

    $response->assertSessionHasErrors([
        'service_zone_id' => 'This zone already has an active price rule. Deactivate it before activating another.',
    ]);
});

test('updating an already-active price rule without changing its zone does not trip the active-duplicate rule against itself', function () {
    $admin = priceRulesManager();
    $zone = ServiceZone::factory()->create();
    $rule = PriceRule::factory()->create(['service_zone_id' => $zone->id, 'status' => Status::Active, 'fee_amount' => 100]);

    $response = $this->actingAs($admin)->put(route('admin.price-rules.update', $rule), validPriceRulePayload([
        'service_zone_id' => $zone->id,
        'status' => Status::Active->value,
        'fee_amount' => 300,
    ]));

    $response->assertRedirect();
    $response->assertSessionDoesntHaveErrors('service_zone_id');
    expect($rule->refresh()->fee_amount)->toBe(300);
});

test('a promotions.manage user can delete a price rule and it is audit-logged', function () {
    $admin = priceRulesManager();
    $rule = PriceRule::factory()->create();

    $response = $this->actingAs($admin)->delete(route('admin.price-rules.destroy', $rule));

    $response->assertRedirect();
    $this->assertModelMissing($rule);
    expect(AuditLog::query()->where('action', 'price_rules.deleted')->where('auditable_type', PriceRule::class)->count())->toBe(1);
});

test('a user holding promotions.view but not promotions.manage can view the index but not mutate', function () {
    // RBAC hardening pass fix, 2026-09-23 (Phase 6): the index route was
    // previously (incorrectly) gated `promotions.manage` too — see
    // routes/admin.php's Promotions block comment. Operations holds
    // `promotions.view` per RolesAndPermissionsSeeder, matching the Phase 6
    // permission matrix's "Promotions: view = Operations" cell, and must
    // reach the read-only index; store/update/destroy correctly stay
    // forbidden since Operations never holds `promotions.manage`.
    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->assignRole('operations'); // locations.manage, promotions.view only
    $rule = PriceRule::factory()->create();

    $this->actingAs($viewer)->get(route('admin.price-rules.index'))->assertOk();
    $this->actingAs($viewer)->post(route('admin.price-rules.store'), validPriceRulePayload())->assertForbidden();
    $this->actingAs($viewer)->put(route('admin.price-rules.update', $rule), validPriceRulePayload())->assertForbidden();
    $this->actingAs($viewer)->delete(route('admin.price-rules.destroy', $rule))->assertForbidden();
});
