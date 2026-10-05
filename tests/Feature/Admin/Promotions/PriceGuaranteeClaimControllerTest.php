<?php

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PriceGuaranteeClaimStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderLineItem;
use App\Models\Payment;
use App\Models\PriceGuaranteeClaim;
use App\Models\TyreVariant;
use App\Models\User;
use App\Services\Payments\StripePaymentGateway;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\FakeStripePaymentGateway;

/**
 * Covers App\Http\Controllers\Admin\Promotions\PriceGuaranteeClaimController
 * — gated `promotions.manage`, not Customer Support, per
 * docs/architecture/07-admin-auth-permissions.md §3.2. Approve validation
 * (the `approved_discount_amount` cap) is mandatory per
 * docs/architecture/05-promotions-pricing.md's security note.
 *
 * Post-purchase approval reuses `RefundService`, which resolves via
 * `PaymentGatewayResolver` off the claim's linked `Payment.gateway` — binds
 * the fake against the concrete `StripePaymentGateway::class` accordingly,
 * see docs/architecture/03-integrations.md's PayPal section, point 3.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->app->instance(StripePaymentGateway::class, $this->gateway = new FakeStripePaymentGateway);
});

function claimsReviewer(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('ecommerce'); // promotions.manage

    return $user;
}

it('approves a pre-purchase claim: sets expires_at, no refund call', function () {
    $admin = claimsReviewer();
    $claim = PriceGuaranteeClaim::factory()->create(['status' => PriceGuaranteeClaimStatus::Pending]);

    $response = $this->actingAs($admin)->post(route('admin.price-guarantee-claims.approve', $claim), [
        'approved_discount_amount' => $claim->tyreVariant->base_price,
        'admin_note' => 'Verified competitor listing.',
    ]);

    $response->assertRedirect();
    $claim->refresh();
    expect($claim->status)->toBe(PriceGuaranteeClaimStatus::Approved);
    expect($claim->expires_at)->not->toBeNull();
    expect($claim->redeemed_at)->toBeNull();
    expect($claim->resolved_by)->toBe($admin->id);
    expect($this->gateway->createdRefunds)->toHaveCount(0);
    expect(AuditLog::query()->where('action', 'price_guarantee_claims.approved')->count())->toBe(1);
});

it('approves a post-purchase claim: reuses the exact refund mechanism and stamps redeemed_at', function () {
    $admin = claimsReviewer();
    $order = Order::factory()->confirmed()->create(['grand_total' => 75600]);
    Payment::factory()->succeeded()->create(['order_id' => $order->id, 'type' => PaymentType::Charge, 'amount' => 75600]);

    $variant = TyreVariant::factory()->create();
    OrderLineItem::factory()->create([
        'order_id' => $order->id,
        'tyre_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price' => 20000,
    ]);

    $claim = PriceGuaranteeClaim::factory()->create([
        'status' => PriceGuaranteeClaimStatus::Pending,
        'order_id' => $order->id,
        'tyre_variant_id' => $variant->id,
    ]);

    $response = $this->actingAs($admin)->post(route('admin.price-guarantee-claims.approve', $claim), [
        'approved_discount_amount' => 5000,
        'admin_note' => 'Approved.',
    ]);

    $response->assertRedirect();
    $claim->refresh();
    expect($claim->status)->toBe(PriceGuaranteeClaimStatus::Approved);
    expect($claim->redeemed_at)->not->toBeNull();
    expect($this->gateway->createdRefunds)->toHaveCount(1);
    expect(Payment::query()->where('order_id', $order->id)->where('type', PaymentType::Refund)->count())->toBe(1);
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::PartiallyRefunded);
});

it('rejects approval when approved_discount_amount exceeds the matched order line value', function () {
    $admin = claimsReviewer();
    $order = Order::factory()->confirmed()->create();
    $variant = TyreVariant::factory()->create();
    OrderLineItem::factory()->create([
        'order_id' => $order->id,
        'tyre_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price' => 20000,
    ]);

    $claim = PriceGuaranteeClaim::factory()->create([
        'status' => PriceGuaranteeClaimStatus::Pending,
        'order_id' => $order->id,
        'tyre_variant_id' => $variant->id,
    ]);

    $response = $this->actingAs($admin)->post(route('admin.price-guarantee-claims.approve', $claim), [
        'approved_discount_amount' => 20001,
    ]);

    $response->assertSessionHasErrors('approved_discount_amount');
    expect($claim->fresh()->status)->toBe(PriceGuaranteeClaimStatus::Pending);
});

it('rejects approval for a pre-purchase claim when approved_discount_amount exceeds the tyre variant base_price', function () {
    $admin = claimsReviewer();
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);
    $claim = PriceGuaranteeClaim::factory()->create([
        'status' => PriceGuaranteeClaimStatus::Pending,
        'tyre_variant_id' => $variant->id,
    ]);

    $response = $this->actingAs($admin)->post(route('admin.price-guarantee-claims.approve', $claim), [
        'approved_discount_amount' => 20001,
    ]);

    $response->assertSessionHasErrors('approved_discount_amount');
});

it('rejects a claim, requiring admin_note', function () {
    $admin = claimsReviewer();
    $claim = PriceGuaranteeClaim::factory()->create(['status' => PriceGuaranteeClaimStatus::Pending]);

    $response = $this->actingAs($admin)->post(route('admin.price-guarantee-claims.reject', $claim), []);
    $response->assertSessionHasErrors('admin_note');

    $response = $this->actingAs($admin)->post(route('admin.price-guarantee-claims.reject', $claim), [
        'admin_note' => 'Not a matching competitor listing.',
    ]);

    $response->assertRedirect();
    $claim->refresh();
    expect($claim->status)->toBe(PriceGuaranteeClaimStatus::Rejected);
    expect($claim->admin_note)->toBe('Not a matching competitor listing.');
    expect(AuditLog::query()->where('action', 'price_guarantee_claims.rejected')->count())->toBe(1);
});

it('cannot re-resolve an already-resolved claim', function () {
    $admin = claimsReviewer();
    $claim = PriceGuaranteeClaim::factory()->rejected()->create();

    $response = $this->actingAs($admin)->post(route('admin.price-guarantee-claims.approve', $claim), [
        'approved_discount_amount' => 1000,
    ]);

    $response->assertRedirect();
    expect($claim->fresh()->status)->toBe(PriceGuaranteeClaimStatus::Rejected);
});

it('allows the index but 403s the approve/reject mutations for customer_support (promotions.view only, not promotions.manage)', function () {
    // RBAC hardening pass fix, 2026-09-23 (Phase 6): the index route was
    // previously (incorrectly) gated `promotions.manage` too — see
    // routes/admin.php's Promotions block comment. Customer Support holds
    // `promotions.view` per RolesAndPermissionsSeeder, matching the Phase 6
    // permission matrix's "Promotions: view = Customer Support" cell, and
    // must reach the read-only review queue; approve/reject correctly stay
    // forbidden since Customer Support never holds `promotions.manage`.
    $support = User::factory()->withTwoFactor()->create();
    $support->assignRole('customer_support');
    $claim = PriceGuaranteeClaim::factory()->create(['status' => PriceGuaranteeClaimStatus::Pending]);

    $this->actingAs($support)->get(route('admin.price-guarantee-claims.index'))->assertOk();
    $this->actingAs($support)->post(route('admin.price-guarantee-claims.approve', $claim), ['approved_discount_amount' => 1000])->assertForbidden();
    $this->actingAs($support)->post(route('admin.price-guarantee-claims.reject', $claim), ['admin_note' => 'no'])->assertForbidden();
});

it('allows the index for operations (promotions.view only, not promotions.manage)', function () {
    // Same fix as above — see that test's comment.
    $operations = User::factory()->withTwoFactor()->create();
    $operations->assignRole('operations');

    $this->actingAs($operations)->get(route('admin.price-guarantee-claims.index'))->assertOk();
});

it('filters the review queue by status', function () {
    $admin = claimsReviewer();
    PriceGuaranteeClaim::factory()->count(2)->create(['status' => PriceGuaranteeClaimStatus::Pending]);
    PriceGuaranteeClaim::factory()->rejected()->create();

    $response = $this->actingAs($admin)->get(route('admin.price-guarantee-claims.index', ['status' => 'pending']));

    $response->assertOk();
});
