<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Commerce\PaymentConfirmationService;
use App\Services\Payments\StripePaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * `POST /api/v1/webhooks/stripe` — Stripe-facing, not storefront-facing.
 * Excluded from Sanctum auth and the `Idempotency-Key` middleware (Stripe's
 * caller sends neither); route-level detail lives in `routes/api.php`. See
 * docs/architecture/02-api-contract.md's "Webhook security & replay-safety"
 * section for the full design this implements.
 *
 * Always returns `200` fast on anything it actually processes (or
 * deliberately no-ops on) — a handful of synchronous DB writes per event,
 * never queued.
 */
class StripeWebhookController extends Controller
{
    /**
     * Stripe's own redelivery window — a materially different constant from
     * the 15-minute guest-secret-replay window elsewhere in this codebase;
     * these are two unrelated numbers solving two unrelated problems.
     */
    private const EVENT_DEDUP_TTL_HOURS = 24;

    public function __construct(
        private readonly StripePaymentGateway $gateway,
        private readonly PaymentConfirmationService $confirmation,
    ) {}

    public function handle(Request $request): Response
    {
        $webhookSecret = (string) config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $webhookSecret,
            );
        } catch (UnexpectedValueException|SignatureVerificationException $e) {
            // Never process an unverified payload — log-only, 400.
            Log::warning('Stripe webhook signature verification failed.', ['error' => $e->getMessage()]);

            return response('', 400);
        }

        // Replay-safety layer 1 (cache-based dedup on event.id, 24h —
        // Stripe's own redelivery window). Only a presence *check* here —
        // the key is deliberately NOT marked until the dispatch below
        // completes without throwing (see the `Cache::put()` call at the
        // bottom of this method). Marking it "handled" up front would let a
        // handler that throws (e.g. PaymentMethod::fromStripe()'s
        // `default => throw` on an unmapped Stripe payment-method type)
        // permanently poison this event.id: Stripe's automatic redelivery
        // of the identical event would then hit this short-circuit and get
        // a 200 without ever reprocessing, stranding the underlying
        // Payment/Order/Booking in `pending` forever even though Stripe
        // already charged the customer. The DB-state backstop inside each
        // handler (e.g. `$payment->status === ...Succeeded` checks) is the
        // actual source of truth for dedup — this cache is a fast-path
        // optimization only and must never be able to permanently poison an
        // event against reprocessing.
        if (Cache::has(self::eventCacheKey($event->id))) {
            return response('', 200);
        }

        // Unhandled event types are expected and intentionally ignored —
        // Stripe delivers many event types a given integration never
        // subscribes to meaningfully; this `default` is a deliberate no-op,
        // not an instance of this project's "unhandled enum value" fragile
        // pattern (that convention is about our own closed domain
        // vocabularies — see PaymentTransactionStatus::fromStripeStatus()
        // for where it actually applies in this file's own call graph).
        //
        // Deliberately not wrapped in try/catch: a thrown exception here
        // (e.g. an unmapped Stripe payment-method/refund-status value) must
        // propagate to Laravel's default exception handling (a 500) so
        // Stripe retries delivery — and, per the comment above, must leave
        // this event.id unmarked so that retry actually reprocesses it.
        match ($event->type) {
            'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event),
            'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event),
            'charge.refunded', 'refund.updated' => $this->handleRefundReconciliation($event),
            default => null,
        };

        // Only mark handled once the dispatch above has completed
        // successfully.
        Cache::put(self::eventCacheKey($event->id), true, now()->addHours(self::EVENT_DEDUP_TTL_HOURS));

        return response('', 200);
    }

    private static function eventCacheKey(string $eventId): string
    {
        return "stripe-webhook-event:{$eventId}";
    }

    /**
     * Confirm booking+order+payment IF the booking is still `pending_hold`
     * and its hold hasn't expired; otherwise this is the rare-but-real
     * "payment confirmed after the hold expired" edge case —
     * `Order.status = refund_required` plus an `AuditLog` row, never
     * silently unreachable. See docs/architecture/01-data-model.md's
     * `Order.refund_required` note.
     */
    private function handlePaymentIntentSucceeded(Event $event): void
    {
        /** @var PaymentIntent $paymentIntent */
        $paymentIntent = $event->data->object;

        // Resolved once, before the transaction (same "gateway call first,
        // DB writes after" shape as RefundService::refund()) rather than
        // while holding the row locks below — this is a real Stripe API
        // round trip (see PaymentMethodResult's docblock for why it can't
        // just be read off the event payload).
        $method = $this->resolvePaymentMethod($paymentIntent);

        DB::transaction(function () use ($paymentIntent, $method): void {
            $payment = Payment::query()
                ->where('gateway_reference', $paymentIntent->id)
                ->where('type', PaymentType::Charge)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                Log::warning('Stripe webhook: payment_intent.succeeded for an unknown gateway_reference.', ['payment_intent_id' => $paymentIntent->id]);

                return;
            }

            // Replay-safety layer 2 (DB state-check backstop): already
            // processed — no-op, covers a cache entry that was evicted or
            // never set.
            if ($payment->status === PaymentTransactionStatus::Succeeded) {
                return;
            }

            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->first();

            if ($order === null) {
                Log::warning('Stripe webhook: payment_intent.succeeded for a payment with no matching order.', ['payment_id' => $payment->id]);

                return;
            }

            $payment->forceFill([
                'status' => PaymentTransactionStatus::Succeeded,
                // Falls back to the row's existing (placeholder) value only
                // for the "shouldn't happen" case resolvePaymentMethod()
                // itself already logged — Stripe's own contract guarantees
                // a succeeded PaymentIntent has a payment_method.
                'method' => $method ?? $payment->method,
                'raw_response' => $paymentIntent->toArray(),
            ])->save();

            $this->confirmation->confirm($order);
        });
    }

    /**
     * Resolves the real instrument used from Stripe's own report — see
     * `PaymentMethod::fromStripe()`'s docblock for why this can't be known
     * at `Payment`-row creation time. Returns `null` (leaving the row's
     * existing placeholder value untouched by the caller) only for the
     * "shouldn't happen" case of a succeeded PaymentIntent with no
     * `payment_method` at all — Stripe's own contract guarantees one is set
     * by this point, so this is treated the same as this file's other
     * "missing linked data" cases (log + continue) rather than
     * `PaymentMethod::fromStripe()`'s own closed-vocabulary
     * `default => throw`, which is reserved for a *present but unrecognized*
     * value.
     */
    private function resolvePaymentMethod(PaymentIntent $paymentIntent): ?PaymentMethod
    {
        // `isset()` (routed through StripeObject::__isset()) rather than a
        // direct property read — avoids the SDK's own "undefined property"
        // notice-level log line for the (expected-absent-per-Stripe's-
        // contract, but real in a malformed/legacy event) case where the
        // key isn't present in `_values` at all.
        $paymentMethodId = isset($paymentIntent->payment_method) ? $paymentIntent->payment_method : null;

        if (! is_string($paymentMethodId) || $paymentMethodId === '') {
            Log::warning('Stripe webhook: payment_intent.succeeded with no payment_method set.', ['payment_intent_id' => $paymentIntent->id]);

            return null;
        }

        $resolved = $this->gateway->retrievePaymentMethod($paymentMethodId);

        return PaymentMethod::fromStripe($resolved->type, $resolved->walletType);
    }

    /**
     * The booking hold is left untouched — no special action needed, it
     * simply continues toward its own natural TTL expiry via the existing
     * sweep, same as any other abandoned checkout.
     */
    private function handlePaymentIntentFailed(Event $event): void
    {
        /** @var PaymentIntent $paymentIntent */
        $paymentIntent = $event->data->object;

        DB::transaction(function () use ($paymentIntent): void {
            $payment = Payment::query()
                ->where('gateway_reference', $paymentIntent->id)
                ->where('type', PaymentType::Charge)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                Log::warning('Stripe webhook: payment_intent.payment_failed for an unknown gateway_reference.', ['payment_intent_id' => $paymentIntent->id]);

                return;
            }

            if ($payment->status === PaymentTransactionStatus::Failed) {
                return;
            }

            $payment->forceFill([
                'status' => PaymentTransactionStatus::Failed,
                'raw_response' => $paymentIntent->toArray(),
            ])->save();

            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->first();

            $order?->forceFill(['payment_status' => PaymentStatus::Failed, 'status' => OrderStatus::PaymentFailed])->save();
        });
    }

    /**
     * Reconciliation backstop only — not the primary refund trigger (the
     * synchronous admin refund flow, {@see RefundService},
     * already updates `Payment`/`Order` on Stripe's direct API response).
     * Catches an async refund-state change Stripe reports later (e.g. a
     * refund failing after initial acceptance).
     */
    private function handleRefundReconciliation(Event $event): void
    {
        $refundObjects = match ($event->type) {
            'refund.updated' => [$event->data->object],
            'charge.refunded' => (array) ($event->data->object->refunds->data ?? []),
            default => [],
        };

        foreach ($refundObjects as $refundObject) {
            $this->reconcileRefund($refundObject);
        }
    }

    /**
     * @param  Refund  $refundObject
     */
    private function reconcileRefund(object $refundObject): void
    {
        DB::transaction(function () use ($refundObject): void {
            $payment = Payment::query()
                ->where('gateway_reference', $refundObject->id)
                ->where('type', PaymentType::Refund)
                ->lockForUpdate()
                ->first();

            // Not a refund this app initiated (or not yet synced) —
            // nothing to reconcile.
            if ($payment === null) {
                return;
            }

            $status = PaymentTransactionStatus::fromStripeStatus($refundObject->status);

            if ($payment->status === $status) {
                return;
            }

            $payment->forceFill(['status' => $status, 'raw_response' => $refundObject->toArray()])->save();
        });
    }
}
