<?php

use App\Enums\PromotionRedemptionStatus;
use App\Enums\Status;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Promotion;
use App\Models\PromotionEligibility;
use App\Models\PromotionRedemption;
use App\Models\User;
use App\Observers\PromotionObserver;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Covers App\Http\Controllers\Admin\Promotions\PromotionController and
 * App\Http\Controllers\Admin\Promotions\PromotionEligibilityController.
 * Mutations (store/update/destroy/eligibilities) are gated
 * `promotions.manage`, not Customer Support/Operations, per
 * docs/architecture/07-admin-auth-permissions.md §3.2 — the read-only
 * `index` route is gated the narrower `promotions.view` instead (Operations
 * and Customer Support both hold it; see the Phase 6 RBAC hardening pass
 * fix in routes/admin.php's Promotions block). Permanent coverage closing
 * the gap flagged by SecurityAgentVerificationTest.php's own docblock ("no
 * existing test file covers these two controllers at all") — that file
 * stays in place as-is (not this file's call to remove it), this one adds
 * the positive-path/render/audit coverage it didn't.
 *
 * `AuditLog` wiring for Promotion mutations is via {@see PromotionObserver}
 * — asserted here, never double-logged by an explicit controller-side call.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function promotionsManager(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('ecommerce'); // promotions.manage

    return $user;
}

function validPromotionPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Spring tyre sale',
        'type' => 'percentage',
        'value' => 10,
        'starts_at' => now()->toDateString(),
        'ends_at' => now()->addMonth()->toDateString(),
        'usage_limit' => null,
        'stock_limit' => null,
        'stackable' => false,
        'status' => Status::Active->value,
    ], $overrides);
}

test('a promotions.manage user can view the campaigns index', function () {
    $admin = promotionsManager();
    Promotion::factory()->create();

    $response = $this->actingAs($admin)->get(route('admin.promotions.index'));

    $response->assertOk();
});

test('a promotions.manage user can create a promotion and it is audit-logged exactly once', function () {
    $admin = promotionsManager();

    $response = $this->actingAs($admin)->post(route('admin.promotions.store'), validPromotionPayload());

    $response->assertRedirect();
    $this->assertDatabaseHas('promotions', ['name' => 'Spring tyre sale', 'value' => 10]);
    expect(AuditLog::query()->where('action', 'promotions.created')->where('auditable_type', Promotion::class)->count())->toBe(1);
});

test('a percentage value over 100 is rejected', function () {
    $admin = promotionsManager();

    $response = $this->actingAs($admin)->post(route('admin.promotions.store'), validPromotionPayload(['value' => 150]));

    $response->assertSessionHasErrors('value');
});

test('an end date before the start date is rejected', function () {
    $admin = promotionsManager();

    $response = $this->actingAs($admin)->post(route('admin.promotions.store'), validPromotionPayload([
        'starts_at' => now()->addWeek()->toDateString(),
        'ends_at' => now()->toDateString(),
    ]));

    $response->assertSessionHasErrors('ends_at');
});

test('a four_for_three campaign accepts value=0 — the grouping algorithm ignores it', function () {
    $admin = promotionsManager();

    $response = $this->actingAs($admin)->post(route('admin.promotions.store'), validPromotionPayload([
        'type' => 'four_for_three',
        'value' => 0,
    ]));

    $response->assertRedirect();
    $this->assertDatabaseHas('promotions', ['type' => 'four_for_three', 'value' => 0]);
});

test('a promotions.manage user can update a promotion and it is audit-logged', function () {
    $admin = promotionsManager();
    $promotion = Promotion::factory()->create(['name' => 'Old name']);

    $response = $this->actingAs($admin)->put(route('admin.promotions.update', $promotion), validPromotionPayload(['name' => 'New name']));

    $response->assertRedirect();
    expect($promotion->refresh()->name)->toBe('New name');
    expect(AuditLog::query()->where('action', 'promotions.updated')->where('auditable_type', Promotion::class)->count())->toBe(1);
});

test('a promotions.manage user can delete a promotion with no redemption history and it is audit-logged', function () {
    $admin = promotionsManager();
    $promotion = Promotion::factory()->create();

    $response = $this->actingAs($admin)->delete(route('admin.promotions.destroy', $promotion));

    $response->assertRedirect();
    $this->assertModelMissing($promotion);
    expect(AuditLog::query()->where('action', 'promotions.deleted')->where('auditable_type', Promotion::class)->count())->toBe(1);
});

test('deleting a promotion with redemption history is blocked, not a raw DB error', function () {
    $admin = promotionsManager();
    $promotion = Promotion::factory()->create();
    PromotionRedemption::factory()->create(['promotion_id' => $promotion->id, 'status' => PromotionRedemptionStatus::Confirmed]);

    $response = $this->actingAs($admin)->delete(route('admin.promotions.destroy', $promotion));

    $response->assertRedirect();
    expect(Promotion::query()->find($promotion->id))->not->toBeNull();
});

test('a promotions.manage user can add and remove an eligibility rule', function () {
    $admin = promotionsManager();
    $promotion = Promotion::factory()->create();
    $brand = Brand::factory()->create();

    $storeResponse = $this->actingAs($admin)->post(route('admin.promotions.eligibilities.store', $promotion), [
        'scope' => 'brand',
        'scope_id' => (string) $brand->id,
    ]);
    $storeResponse->assertRedirect();
    $this->assertDatabaseHas('promotion_eligibilities', [
        'promotion_id' => $promotion->id,
        'scope' => 'brand',
        'scope_id' => (string) $brand->id,
    ]);

    $eligibility = PromotionEligibility::query()->where('promotion_id', $promotion->id)->firstOrFail();

    $destroyResponse = $this->actingAs($admin)->delete(route('admin.promotions.eligibilities.destroy', [$promotion, $eligibility]));
    $destroyResponse->assertRedirect();
    $this->assertModelMissing($eligibility);
});

test('an eligibility rule with an unresolvable scope_id is rejected', function () {
    $admin = promotionsManager();
    $promotion = Promotion::factory()->create();

    $response = $this->actingAs($admin)->post(route('admin.promotions.eligibilities.store', $promotion), [
        'scope' => 'brand',
        'scope_id' => '999999',
    ]);

    $response->assertSessionHasErrors('scope_id');
});

test('a category-scope eligibility rule validates scope_id against the fixed TyreCategory vocabulary', function () {
    $admin = promotionsManager();
    $promotion = Promotion::factory()->create();

    $valid = $this->actingAs($admin)->post(route('admin.promotions.eligibilities.store', $promotion), [
        'scope' => 'category',
        'scope_id' => 'suv',
    ]);
    $valid->assertRedirect();
    $valid->assertSessionDoesntHaveErrors('scope_id');

    $invalid = $this->actingAs($admin)->post(route('admin.promotions.eligibilities.store', $promotion), [
        'scope' => 'category',
        'scope_id' => 'not-a-real-category',
    ]);
    $invalid->assertSessionHasErrors('scope_id');
});

test('a user holding promotions.view but not promotions.manage can view the index but not mutate', function () {
    // RBAC hardening pass fix, 2026-09-23 (Phase 6): this test previously
    // asserted the index route was forbidden too — that was the drift this
    // fix corrects (see routes/admin.php's Promotions block comment).
    // Operations holds `promotions.view` per RolesAndPermissionsSeeder,
    // matching the Phase 6 permission matrix's "Promotions: view =
    // Operations" cell, and must reach the read-only index; the store
    // mutation correctly stays forbidden since Operations never holds
    // `promotions.manage`.
    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->assignRole('operations'); // locations.manage, promotions.view only
    $promotion = Promotion::factory()->create();

    $this->actingAs($viewer)->get(route('admin.promotions.index'))->assertOk();
    $this->actingAs($viewer)->post(route('admin.promotions.store'), validPromotionPayload())->assertForbidden();
});
