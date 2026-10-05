<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Commerce\RefundService;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\Payments\PayPalPaymentGateway;
use App\Services\Payments\StripePaymentGateway;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakePayPalPaymentGateway;
use Tests\Support\FakeStripePaymentGateway;

/**
 * The refund mechanism behind `POST /admin/orders/{order}/refund` — see
 * docs/architecture/02-api-contract.md's "Orders admin — refund flow"
 * section.
 *
 * Binds the fake against the concrete `StripePaymentGateway::class` (not
 * the generic `PaymentGateway` interface) — `RefundService` resolves via
 * `PaymentGatewayResolver`, keyed off each charge `Payment`'s own `gateway`
 * column, never the active-config singleton. See
 * docs/architecture/03-integrations.md's PayPal section, point 3.
 */
beforeEach(function () {
    $this->gateway = new FakeStripePaymentGateway;
    $this->app->instance(StripePaymentGateway::class, $this->gateway);
    $this->service = new RefundService($this->app->make(PaymentGatewayResolver::class));
    $this->actor = User::factory()->create();
});

it('fully refunds the remaining balance when amount is omitted', function () {
    $order = Order::factory()->confirmed()->create(['grand_total' => 75600]);
    $charge = Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'type' => PaymentType::Charge,
        'amount' => 75600,
        'gateway_reference' => 'pi_original123',
    ]);
    $idempotencyKey = (string) Str::uuid();

    $payment = $this->service->refund($order, $this->actor, null, 'Customer request', $idempotencyKey);

    expect($payment->type)->toBe(PaymentType::Refund);
    expect($payment->amount)->toBe(75600);
    expect($payment->status)->toBe(PaymentTransactionStatus::Succeeded);
    expect($payment->gateway_reference)->toStartWith('re_');
    expect($payment->idempotency_key)->toBe($idempotencyKey);

    $order->refresh();
    expect($order->payment_status)->toBe(PaymentStatus::Refunded);
    expect($order->status)->toBe(OrderStatus::Refunded);

    $audit = AuditLog::query()->where('auditable_type', Order::class)->where('auditable_id', $order->id)->sole();
    expect($audit->action)->toBe('orders.refunded');
    expect($audit->actor_id)->toBe($this->actor->id);
    expect($audit->after['reason'])->toBe('Customer request');

    expect($this->gateway->createdRefunds)->toHaveCount(1);
    expect($this->gateway->createdRefunds[0]['paymentIntentId'])->toBe('pi_original123');
    // The client-supplied key must flow through unchanged as Stripe's own
    // idempotency key — no server-generated UUID.
    expect($this->gateway->createdRefunds[0]['idempotencyKey'])->toBe($idempotencyKey);
});

it('partially refunds a specific amount, leaving Order.status untouched', function () {
    $order = Order::factory()->confirmed()->create(['grand_total' => 75600]);
    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'type' => PaymentType::Charge,
        'amount' => 75600,
    ]);

    $payment = $this->service->refund($order, $this->actor, 20000, 'Goodwill partial refund', (string) Str::uuid());

    expect($payment->amount)->toBe(20000);

    $order->refresh();
    expect($order->payment_status)->toBe(PaymentStatus::PartiallyRefunded);
    expect($order->status)->toBe(OrderStatus::Confirmed);
});

it('rejects a refund amount exceeding the remaining refundable balance', function () {
    $order = Order::factory()->confirmed()->create(['grand_total' => 75600]);
    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'type' => PaymentType::Charge,
        'amount' => 75600,
    ]);

    expect(fn () => $this->service->refund($order, $this->actor, 75601, 'Too much', (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    expect($this->gateway->createdRefunds)->toBeEmpty();
    expect(Payment::query()->where('type', PaymentType::Refund)->count())->toBe(0);
});

it('accounts for a prior partial refund when validating the remaining balance', function () {
    $order = Order::factory()->confirmed()->create(['grand_total' => 75600]);
    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'type' => PaymentType::Charge,
        'amount' => 75600,
    ]);
    Payment::factory()->refund()->create([
        'order_id' => $order->id,
        'amount' => 50000,
    ]);

    // Remaining balance is 25600 — requesting more must fail.
    expect(fn () => $this->service->refund($order, $this->actor, 25601, 'Too much', (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    // Exactly the remaining balance succeeds and fully refunds the order.
    $payment = $this->service->refund($order, $this->actor, 25600, 'Remainder', (string) Str::uuid());
    expect($payment->amount)->toBe(25600);
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Refunded);
});

it('rejects a refund when there is no succeeded charge on the order', function () {
    $order = Order::factory()->create();

    expect(fn () => $this->service->refund($order, $this->actor, null, 'No charge', (string) Str::uuid()))
        ->toThrow(ValidationException::class);
});

it('returns the existing Payment row and calls Stripe exactly once when the same Idempotency-Key is reused (double-click dedup)', function () {
    $order = Order::factory()->confirmed()->create(['grand_total' => 75600]);
    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'type' => PaymentType::Charge,
        'amount' => 75600,
    ]);
    $idempotencyKey = (string) Str::uuid();

    $first = $this->service->refund($order, $this->actor, 20000, 'Goodwill partial refund', $idempotencyKey);
    $second = $this->service->refund($order, $this->actor, 20000, 'Goodwill partial refund', $idempotencyKey);

    expect($second->id)->toBe($first->id);
    expect($this->gateway->createdRefunds)->toHaveCount(1);
    expect(Payment::query()->where('order_id', $order->id)->where('type', PaymentType::Refund)->count())->toBe(1);

    // The second call must not have re-applied the refund to the order —
    // still exactly one partial-refund's worth of movement.
    expect($order->fresh()->payment_status)->toBe(PaymentStatus::PartiallyRefunded);
    expect(AuditLog::query()->where('action', 'orders.refunded')->where('auditable_id', $order->id)->count())->toBe(1);
});

/**
 * Adapted from `BookingStoreTest.php`'s "closes the true concurrent-insert
 * race for a shared Idempotency-Key..." test (also reused by
 * `OrderStoreTest.php`'s equivalent race test on `Order::create()`) — see
 * that test's docblock for the full reasoning on why a real two-connection
 * race can't be reproduced inside RefreshDatabase's single wrapping
 * transaction (it deadlocks against itself), and why this decouples the two
 * things that would happen atomically under real concurrency instead: (1)
 * this call's own `Payment::create()` failing with the exact exception type
 * MySQL raises for a duplicate `idempotency_key`
 * (`UniqueConstraintViolationException`, constructed directly rather than
 * provoked), and (2) the competing refund `Payment` row becoming committed
 * and visible on this same connection once this call's own transaction has
 * actually rolled back (`TransactionRolledBack`, fired post-rollback).
 */
it('closes the true concurrent-insert race for a shared Idempotency-Key by returning the committed winner instead of a raw exception', function () {
    $order = Order::factory()->confirmed()->create(['grand_total' => 75600]);
    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'type' => PaymentType::Charge,
        'amount' => 75600,
    ]);
    $idempotencyKey = (string) Str::uuid();

    $winningGatewayReference = 're_'.Str::random(24);
    $committedWinner = false;

    Event::listen(TransactionRolledBack::class, function () use (&$committedWinner, $order, $idempotencyKey, $winningGatewayReference): void {
        if ($committedWinner) {
            return;
        }

        $committedWinner = true;

        DB::table('payments')->insert([
            'order_id' => $order->id,
            'type' => PaymentType::Refund->value,
            'gateway' => 'stripe',
            'method' => 'card',
            'status' => PaymentTransactionStatus::Succeeded->value,
            'amount' => 20000,
            'gateway_reference' => $winningGatewayReference,
            'idempotency_key' => $idempotencyKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    Payment::creating(function (Payment $model) use ($idempotencyKey): void {
        if ($model->idempotency_key !== $idempotencyKey) {
            return;
        }

        $previous = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '{$idempotencyKey}' for key 'payments.idempotency_key'");
        $previous->errorInfo = ['23000', 1062, "Duplicate entry '{$idempotencyKey}' for key 'payments.idempotency_key'"];

        throw new UniqueConstraintViolationException('mysql', 'insert into `payments` (`idempotency_key`, ...) values (?, ...)', [], $previous);
    });

    $payment = $this->service->refund($order, $this->actor, 20000, 'Concurrent resubmit', $idempotencyKey);

    expect(Payment::query()->where('order_id', $order->id)->where('type', PaymentType::Refund)->count())->toBe(1);
    expect($payment->gateway_reference)->toBe($winningGatewayReference);
    expect($payment->idempotency_key)->toBe($idempotencyKey);
    // Stripe was still called exactly once by this request — the dedup on
    // the losing side happens only at the DB-insert layer, not before the
    // gateway call, which is safe per this method's own docblock: Stripe
    // itself deduplicates identical idempotency keys.
    expect($this->gateway->createdRefunds)->toHaveCount(1);
});

/**
 * PayPal-as-second-gateway coverage — see
 * docs/architecture/03-integrations.md's PayPal section, point 3, and
 * docs/architecture/01-data-model.md's `Payment` section.
 */
it('targets the capture id (gateway_capture_reference), not the order id, for a PayPal refund', function () {
    $this->app->instance(PayPalPaymentGateway::class, $paypal = new FakePayPalPaymentGateway);

    $order = Order::factory()->confirmed()->create(['grand_total' => 75600]);
    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'type' => PaymentType::Charge,
        'amount' => 75600,
        'gateway' => PaymentGateway::PayPal,
        'gateway_reference' => 'EC-ORDER123',
        'gateway_capture_reference' => '1CA-CAPTURE456',
    ]);

    $payment = $this->service->refund($order, $this->actor, null, 'Customer request', (string) Str::uuid());

    expect($payment->gateway)->toBe(PaymentGateway::PayPal);
    expect($paypal->createdRefunds)->toHaveCount(1);
    // The capture id, never the order id — a PayPal refund targets the
    // capture, not the order.
    expect($paypal->createdRefunds[0]['captureId'])->toBe('1CA-CAPTURE456');
    expect($this->gateway->createdRefunds)->toBeEmpty();
});

it('refunds against the original charge\'s own gateway even after PAYMENT_GATEWAY has since switched (cross-gateway resolution)', function () {
    $this->app->instance(PayPalPaymentGateway::class, $paypal = new FakePayPalPaymentGateway);

    $order = Order::factory()->confirmed()->create(['grand_total' => 75600]);
    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'type' => PaymentType::Charge,
        'amount' => 75600,
        'gateway' => PaymentGateway::Stripe,
        'gateway_reference' => 'pi_original_stripe_charge',
    ]);

    // Merchant has since switched the active gateway to PayPal — this must
    // not affect a refund against the Stripe-era order above.
    config(['services.payment_gateway' => 'paypal']);

    $payment = $this->service->refund($order, $this->actor, null, 'Refund after gateway switch', (string) Str::uuid());

    expect($payment->gateway)->toBe(PaymentGateway::Stripe);
    expect($this->gateway->createdRefunds)->toHaveCount(1);
    expect($this->gateway->createdRefunds[0]['paymentIntentId'])->toBe('pi_original_stripe_charge');
    expect($paypal->createdRefunds)->toBeEmpty();
});

it('throws when a PayPal charge has no gateway_capture_reference (never actually captured)', function () {
    $this->app->instance(PayPalPaymentGateway::class, new FakePayPalPaymentGateway);

    $order = Order::factory()->confirmed()->create(['grand_total' => 75600]);
    Payment::factory()->succeeded()->create([
        'order_id' => $order->id,
        'type' => PaymentType::Charge,
        'amount' => 75600,
        'gateway' => PaymentGateway::PayPal,
        'gateway_reference' => 'EC-ORDER123',
        'gateway_capture_reference' => null,
    ]);

    expect(fn () => $this->service->refund($order, $this->actor, null, 'Should fail', (string) Str::uuid()))
        ->toThrow(RuntimeException::class);
});
