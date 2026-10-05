<?php

use App\Enums\CancellationFeeType;
use App\Enums\Status;
use App\Models\CancellationPolicy;
use App\Models\ServiceZone;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Covers App\Http\Controllers\Admin\Bookings\CancellationPolicyController and
 * App\Http\Requests\Admin\Bookings\CancellationPolicyRequest. The `after()`
 * "at most one active policy per zone (including the global/null key)" rule
 * backstops CancellationPolicy::forZone()'s "first active row wins" lookup —
 * see the request class's docblock — so both of its distinct user-facing
 * messages, and the update path's self-exclusion, get their own tests below.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function cancellationPoliciesManager(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('operations');

    return $user;
}

function validPolicyPayload(array $overrides = []): array
{
    return array_merge([
        'service_zone_id' => null,
        'notice_hours' => 24,
        'fee_type' => CancellationFeeType::Flat->value,
        'fee_amount' => 5000,
        'fee_percent' => null,
        'status' => Status::Inactive->value,
    ], $overrides);
}

test('a bookings.manage user can create a zone-specific cancellation policy', function () {
    $admin = cancellationPoliciesManager();
    $zone = ServiceZone::factory()->create();

    $response = $this->actingAs($admin)->post(route('admin.bookings.cancellation-policies.store'), validPolicyPayload([
        'service_zone_id' => $zone->id,
    ]));

    $response->assertRedirect();
    $this->assertDatabaseHas('cancellation_policies', [
        'service_zone_id' => $zone->id,
        'notice_hours' => 24,
        'fee_amount' => 5000,
    ]);
});

test('a bookings.manage user can update a cancellation policy', function () {
    $admin = cancellationPoliciesManager();
    $policy = CancellationPolicy::factory()->create(['notice_hours' => 0, 'status' => Status::Inactive]);

    $response = $this->actingAs($admin)->put(route('admin.bookings.cancellation-policies.update', $policy), validPolicyPayload([
        'notice_hours' => 48,
    ]));

    $response->assertRedirect();
    expect($policy->refresh()->notice_hours)->toBe(48);
});

test('fee_amount is required with a user-visible message when fee_type is flat', function () {
    $admin = cancellationPoliciesManager();

    $response = $this->actingAs($admin)->post(route('admin.bookings.cancellation-policies.store'), validPolicyPayload([
        'fee_type' => CancellationFeeType::Flat->value,
        'fee_amount' => null,
    ]));

    $response->assertSessionHasErrors(['fee_amount' => 'A fee amount is required for flat fees.']);
});

test('fee_percent is required with a user-visible message when fee_type is percent', function () {
    $admin = cancellationPoliciesManager();

    $response = $this->actingAs($admin)->post(route('admin.bookings.cancellation-policies.store'), validPolicyPayload([
        'fee_type' => CancellationFeeType::Percent->value,
        'fee_amount' => null,
        'fee_percent' => null,
    ]));

    $response->assertSessionHasErrors(['fee_percent' => 'A fee percentage is required for percent fees.']);
});

test('fee_amount is not required when fee_type is percent', function () {
    $admin = cancellationPoliciesManager();

    $response = $this->actingAs($admin)->post(route('admin.bookings.cancellation-policies.store'), validPolicyPayload([
        'fee_type' => CancellationFeeType::Percent->value,
        'fee_amount' => null,
        'fee_percent' => 10,
    ]));

    $response->assertSessionDoesntHaveErrors(['fee_amount', 'fee_percent']);
});

test('creating an active global policy while one already exists is rejected with the global-specific message', function () {
    $admin = cancellationPoliciesManager();
    CancellationPolicy::factory()->create(['service_zone_id' => null, 'status' => Status::Active]);

    $response = $this->actingAs($admin)->post(route('admin.bookings.cancellation-policies.store'), validPolicyPayload([
        'service_zone_id' => null,
        'status' => Status::Active->value,
    ]));

    $response->assertSessionHasErrors([
        'service_zone_id' => 'An active global default policy already exists. Deactivate it before activating another.',
    ]);
});

test('creating an active zone policy while one already exists for that zone is rejected with the zone-specific message', function () {
    $admin = cancellationPoliciesManager();
    $zone = ServiceZone::factory()->create();
    CancellationPolicy::factory()->create(['service_zone_id' => $zone->id, 'status' => Status::Active]);

    $response = $this->actingAs($admin)->post(route('admin.bookings.cancellation-policies.store'), validPolicyPayload([
        'service_zone_id' => $zone->id,
        'status' => Status::Active->value,
    ]));

    $response->assertSessionHasErrors([
        'service_zone_id' => 'This zone already has an active cancellation policy. Deactivate it before activating another.',
    ]);
});

test('creating an inactive zone policy while an active one already exists for that zone succeeds', function () {
    $admin = cancellationPoliciesManager();
    $zone = ServiceZone::factory()->create();
    CancellationPolicy::factory()->create(['service_zone_id' => $zone->id, 'status' => Status::Active]);

    $response = $this->actingAs($admin)->post(route('admin.bookings.cancellation-policies.store'), validPolicyPayload([
        'service_zone_id' => $zone->id,
        'status' => Status::Inactive->value,
    ]));

    $response->assertRedirect();
    $response->assertSessionDoesntHaveErrors('service_zone_id');
});

test('updating an already-active policy without changing its zone does not trip the active-duplicate rule against itself', function () {
    $admin = cancellationPoliciesManager();
    $zone = ServiceZone::factory()->create();
    $policy = CancellationPolicy::factory()->create([
        'service_zone_id' => $zone->id,
        'status' => Status::Active,
        'notice_hours' => 12,
    ]);

    $response = $this->actingAs($admin)->put(route('admin.bookings.cancellation-policies.update', $policy), validPolicyPayload([
        'service_zone_id' => $zone->id,
        'status' => Status::Active->value,
        'notice_hours' => 36,
    ]));

    $response->assertRedirect();
    $response->assertSessionDoesntHaveErrors('service_zone_id');
    expect($policy->refresh()->notice_hours)->toBe(36);
});

test('deleting the global default policy is rejected with an error toast and the row is kept', function () {
    $admin = cancellationPoliciesManager();
    $policy = CancellationPolicy::factory()->create(['service_zone_id' => null]);

    $response = $this->actingAs($admin)->delete(route('admin.bookings.cancellation-policies.destroy', $policy));

    $response->assertRedirect();
    $response->assertInertiaFlash('toast.type', 'error');
    $this->assertModelExists($policy);
});

test('deleting a zone-specific policy succeeds with a success toast', function () {
    $admin = cancellationPoliciesManager();
    $zone = ServiceZone::factory()->create();
    $policy = CancellationPolicy::factory()->create(['service_zone_id' => $zone->id]);

    $response = $this->actingAs($admin)->delete(route('admin.bookings.cancellation-policies.destroy', $policy));

    $response->assertRedirect();
    $response->assertInertiaFlash('toast.type', 'success');
    $this->assertModelMissing($policy);
});

test('a user without bookings.manage is forbidden from viewing, creating, updating, or deleting cancellation policies', function () {
    $viewer = User::factory()->withTwoFactor()->create();
    $viewer->assignRole('ecommerce');
    $policy = CancellationPolicy::factory()->create();

    $this->actingAs($viewer)->get(route('admin.bookings.cancellation-policies.index'))->assertForbidden();
    $this->actingAs($viewer)->post(route('admin.bookings.cancellation-policies.store'), validPolicyPayload())->assertForbidden();
    $this->actingAs($viewer)->put(route('admin.bookings.cancellation-policies.update', $policy), validPolicyPayload())->assertForbidden();
    $this->actingAs($viewer)->delete(route('admin.bookings.cancellation-policies.destroy', $policy))->assertForbidden();
});
