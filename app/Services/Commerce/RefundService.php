<?php

namespace App\Services\Commerce;

use App\Enums\OrderStatus;
use App\Enums\PaymentGateway as PaymentGatewayEnum;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaymentGatewayResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The refund mechanism behind `POST /admin/orders/{order}/refund` — see
 * docs/architecture/02-api-contract.md's "Orders admin — refund flow"
 * section. Deliberately just a service, no controller/route of its own
 * yet: super-admin-agent wires the Inertia route/UI in a later round
 * against this class's single public method, gated on the `orders.refund`
 * permission (never `orders.manage`) — see
 * docs/architecture/07-admin-auth-permissions.md §3.2.
 *
 * Resolves the gateway implementation via {@see PaymentGatewayResolver},
 * keyed on the *original charge's own* `gateway` column — never the
 * currently-active `PAYMENT_GATEWAY`-configured singleton. A refund on an
 * old order must still work correctly after the merchant has since switched
 * gateways: injecting the "active" singleton here would silently call the
 * wrong provider's API against a foreign reference id the first time
 * `PAYMENT_GATEWAY` is ever switched with open orders on the books. See
 * docs/architecture/03-integrations.md's PayPal section, point 3.
 */
class RefundService
{
    public function __construct(private readonly PaymentGatewayResolver $gatewayResolver) {}

    /**
     * Refund `$amountCents` (null = full remaining refundable balance)
     * against `$order`'s original charge. `$idempotencyKey` is
     * client-supplied (the admin refund route's `Idempotency-Key` header,
     * guarded by the same `idempotency` middleware as
     * `POST /api/v1/bookings`/`POST /api/v1/orders`) — a genuine double
     * submission (double-click, network-lag resubmit, back-button replay)
     * carries the *same* key, so this method looks up an existing `Payment`
     * row by `(order_id, idempotency_key)` first and returns it unchanged
     * instead of calling Stripe again. This mirrors
     * `OrderController::store()`'s replay-path shape exactly, and the same
     * key is also passed through as Stripe's own `idempotency_key` request
     * option, so even a genuine race between two concurrent requests
     * (both missing the pre-check) still resolves to a single Stripe-side
     * refund — Stripe itself deduplicates identical idempotency keys.
     *
     * Validates the amount against the remaining balance, calls Stripe's
     * Refund API, then atomically records a new `type=refund` `Payment`
     * row, updates `Order.payment_status`/`status`, and writes an
     * `AuditLog` row.
     *
     * @throws ValidationException if `$amountCents` exceeds the remaining
     *                             refundable balance, or nothing remains
     *                             to refund.
     */
    public function refund(Order $order, User $actor, ?int $amountCents, string $reason, string $idempotencyKey): Payment
    {
        $existing = Payment::query()
            ->where('order_id', $order->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $chargePayment = $order->payments()
            ->where('type', PaymentType::Charge)
            ->where('status', PaymentTransactionStatus::Succeeded)
            ->latest('id')
            ->first();

        if ($chargePayment === null) {
            throw ValidationException::withMessages([
                'amount' => [__('This order has no succeeded charge to refund.')],
            ]);
        }

        $refundedSoFar = (int) $order->payments()->where('type', PaymentType::Refund)->sum('amount');
        $remaining = $chargePayment->amount - $refundedSoFar;

        $requestedAmount = $amountCents ?? $remaining;

        if ($remaining <= 0) {
            throw ValidationException::withMessages([
                'amount' => [__('There is no refundable balance remaining on this order.')],
            ]);
        }

        if ($requestedAmount <= 0 || $requestedAmount > $remaining) {
            throw ValidationException::withMessages([
                'amount' => [__('The refund amount must be between 1 cent and the remaining refundable balance of :remaining cents.', ['remaining' => $remaining])],
            ]);
        }

        $gateway = $this->gatewayResolver->resolve($chargePayment->gateway);
        $gatewayReferenceToRefund = $this->refundableReference($chargePayment);

        $result = $gateway->createRefund($gatewayReferenceToRefund, $requestedAmount, $idempotencyKey);

        try {
            return DB::transaction(function () use ($order, $chargePayment, $result, $requestedAmount, $idempotencyKey, $actor, $reason, $refundedSoFar): Payment {
                $payment = Payment::create([
                    'order_id' => $order->id,
                    'type' => PaymentType::Refund,
                    // Mirrors the original charge's gateway — never
                    // hardcoded — so a refund row always reflects which
                    // provider actually processed it, regardless of
                    // whichever gateway is currently active in config.
                    'gateway' => $chargePayment->gateway,
                    'method' => $chargePayment->method,
                    'status' => $this->resolveRefundStatus($chargePayment->gateway, $result->status),
                    'amount' => $requestedAmount,
                    'gateway_reference' => $result->id,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $newRefundedTotal = $refundedSoFar + $requestedAmount;
                $fullyRefunded = $newRefundedTotal >= $chargePayment->amount;

                $beforePaymentStatus = $order->payment_status;
                $beforeStatus = $order->status;

                $order->forceFill([
                    'payment_status' => $fullyRefunded ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
                    'status' => $fullyRefunded ? OrderStatus::Refunded : $order->status,
                ])->save();

                AuditLog::create([
                    'auditable_type' => Order::class,
                    'auditable_id' => $order->id,
                    'action' => 'orders.refunded',
                    'actor_id' => $actor->id,
                    'before' => [
                        'payment_status' => $beforePaymentStatus->value,
                        'status' => $beforeStatus->value,
                        'refunded_total' => $refundedSoFar,
                    ],
                    'after' => [
                        'payment_status' => $order->payment_status->value,
                        'status' => $order->status->value,
                        'refunded_total' => $newRefundedTotal,
                        'reason' => $reason,
                    ],
                ]);

                return $payment;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Mirrors OrderController::store()'s identical race-recovery
            // shape: a genuinely concurrent second request carrying the
            // same Idempotency-Key can lose the race between this method's
            // pre-check above and its own INSERT (both may have already
            // reached Stripe too — safe, since both passed the same
            // `$idempotencyKey` as Stripe's own idempotency key, so Stripe
            // itself resolves them to a single underlying refund). Re-fetch
            // and return the winner instead of surfacing a raw 500.
            $existing = Payment::query()
                ->where('order_id', $order->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Which stored reference `createRefund()` actually needs, per gateway —
     * Stripe targets the original `PaymentIntent` id (`gateway_reference`,
     * unchanged); PayPal targets the **capture** id
     * (`gateway_capture_reference`), never the Order id, since a PayPal
     * refund is issued against the capture, not the order. See
     * docs/architecture/01-data-model.md's `Payment` section.
     */
    private function refundableReference(Payment $chargePayment): string
    {
        return match ($chargePayment->gateway) {
            PaymentGatewayEnum::Stripe => $chargePayment->gateway_reference,
            PaymentGatewayEnum::PayPal => $chargePayment->gateway_capture_reference
                ?? throw new RuntimeException("PayPal charge Payment #{$chargePayment->id} has no gateway_capture_reference — it was never actually captured, so it cannot be refunded."),
            PaymentGatewayEnum::Zip => throw new RuntimeException('Zip is a reserved gateway enum value with no PaymentGateway implementation yet.'),
        };
    }

    private function resolveRefundStatus(PaymentGatewayEnum $gateway, string $gatewayStatus): PaymentTransactionStatus
    {
        return match ($gateway) {
            PaymentGatewayEnum::Stripe => PaymentTransactionStatus::fromStripeStatus($gatewayStatus),
            PaymentGatewayEnum::PayPal => PaymentTransactionStatus::fromPayPalRefundStatus($gatewayStatus),
            PaymentGatewayEnum::Zip => throw new RuntimeException('Zip is a reserved gateway enum value with no PaymentGateway implementation yet.'),
        };
    }
}
