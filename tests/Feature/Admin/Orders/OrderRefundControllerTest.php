<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\StripePaymentGateway;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Tests\Support\FakeStripePaymentGateway;

/**
 * `POST /admin/orders/{order}/refund` — see
 * docs/architecture/02-api-contract.md's "Orders admin — refund flow"
 * section and docs/architecture/07-admin-auth-permissions.md §3.2. Gated on
 * `orders.refund`, never `orders.manage`, and guarded by the `idempotency`
 * middleware (`Idempotency-Key` header, same convention as
 * `POST /api/v1/bookings`/`POST /api/v1/orders`).
 *
 * Binds the fake against the concrete `StripePaymentGateway::class` — the
 * underlying `RefundService` resolves via `PaymentGatewayResolver`, keyed
 * off each charge `Payment`'s own `gateway` column (every `refundableOrder()`
 * fixture below is a Stripe charge, `PaymentFactory`'s default), never the
 * active-config singleton. See docs/architecture/03-integrations.md's
 * PayPal section, point 3.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->app->instance(StripePaymentGateway::class, $this->gateway = new FakeStripePaymentGateway);
});

/**
 * @return array<string, string>
 */
function idempotencyHeader(?string $key = null): array
{
    return ['Idempotency-Key' => $key ?? (string) Str::uuid()];
}

function refundableOrder(): Order
{
    $order = Order::factory()->confirmed()->create(['grand_total' => 75600]);
    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'type' => PaymentType::Charge,
        'amount' => 75600,
    ]);

    return $order;
}

it('processes a full refund for a user holding orders.refund (operations)', function () {
    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole('operations');
    $order = refundableOrder();

    $response = $this->actingAs($admin)->post(route('admin.orders.refund', $order), ['reason' => 'Customer requested cancellation'], idempotencyHeader());

    $response->assertRedirect();
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Refunded);
    expect($order->payment_status)->toBe(PaymentStatus::Refunded);
    expect(Payment::query()->where('order_id', $order->id)->where('type', PaymentType::Refund)->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'orders.refunded')->count())->toBe(1);
});

it('403s for a user holding orders.manage but not orders.refund (customer_support)', function () {
    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole('customer_support');
    $order = refundableOrder();

    $response = $this->actingAs($admin)->post(route('admin.orders.refund', $order), ['reason' => 'Should be denied'], idempotencyHeader());

    $response->assertForbidden();
    expect($order->fresh()->status)->not->toBe(OrderStatus::Refunded);
});

it('422s without an Idempotency-Key header', function () {
    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole('super_admin');
    $order = refundableOrder();

    $response = $this->actingAs($admin)->post(route('admin.orders.refund', $order), ['reason' => 'Missing header']);

    $response->assertStatus(422);
    expect($order->fresh()->status)->not->toBe(OrderStatus::Refunded);
});

/**
 * Fast-follow: this Inertia/session-auth route reuses `Idempotency`
 * verbatim from the Sanctum-token JSON API routes, but a raw JSON 422
 * without an `X-Inertia` header doesn't degrade cleanly through Inertia's
 * client (see `App\Http\Middleware\Idempotency`'s docblock). On a request
 * carrying `X-Inertia: true`, the middleware must redirect back with
 * `errors` flashed to the session instead, exactly like every other admin
 * form's validation failure.
 */
it('redirects back with a session error instead of a raw JSON 422 when the Idempotency-Key header is missing on an Inertia request', function () {
    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole('super_admin');
    $order = refundableOrder();

    $response = $this->actingAs($admin)
        ->withHeader('X-Inertia', 'true')
        ->post(route('admin.orders.refund', $order), ['reason' => 'Missing header']);

    $response->assertRedirect();
    $response->assertSessionHasErrors('idempotency_key');
    expect($order->fresh()->status)->not->toBe(OrderStatus::Refunded);
});

it('redirects back with a session error instead of a raw JSON 422 when the Idempotency-Key header is malformed on an Inertia request', function () {
    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole('super_admin');
    $order = refundableOrder();

    $response = $this->actingAs($admin)
        ->withHeader('X-Inertia', 'true')
        ->post(route('admin.orders.refund', $order), ['reason' => 'Malformed header'], idempotencyHeader('not-a-uuid'));

    $response->assertRedirect();
    $response->assertSessionHasErrors('idempotency_key');
    expect($order->fresh()->status)->not->toBe(OrderStatus::Refunded);
});

it('422s without a reason', function () {
    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole('super_admin');
    $order = refundableOrder();

    $response = $this->actingAs($admin)->post(route('admin.orders.refund', $order), [], idempotencyHeader());

    $response->assertSessionHasErrors('reason');
});

it('redirects back with a validation error when the amount exceeds the refundable balance, without touching the order', function () {
    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole('super_admin');
    $order = refundableOrder();

    $response = $this->actingAs($admin)->post(route('admin.orders.refund', $order), [
        'amount' => 999999,
        'reason' => 'Too much',
    ], idempotencyHeader());

    $response->assertSessionHasErrors('amount');
    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
});

it('processes exactly one refund when the same Idempotency-Key is replayed (double-click/back-button/network-lag double-submit)', function () {
    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole('super_admin');
    $order = refundableOrder();
    $key = (string) Str::uuid();

    $first = $this->actingAs($admin)->post(route('admin.orders.refund', $order), [
        'amount' => 20000,
        'reason' => 'Goodwill partial refund',
    ], idempotencyHeader($key));

    $second = $this->actingAs($admin)->post(route('admin.orders.refund', $order), [
        'amount' => 20000,
        'reason' => 'Goodwill partial refund',
    ], idempotencyHeader($key));

    $first->assertRedirect();
    $second->assertRedirect();

    expect($this->gateway->createdRefunds)->toHaveCount(1);
    expect(Payment::query()->where('order_id', $order->id)->where('type', PaymentType::Refund)->count())->toBe(1);
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::PartiallyRefunded);
});
