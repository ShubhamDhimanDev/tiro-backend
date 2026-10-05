<?php

namespace App\Services\Commerce;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\PayPalCaptureDeclinedException;
use App\Services\Payments\PayPalPaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The shared PayPal capture-confirmation logic — callable from both
 * `POST /api/v1/orders/{order}/paypal-capture` (the primary, synchronous
 * path, `captureAndConfirm()`) and `PayPalWebhookController`'s
 * `PAYMENT.CAPTURE.COMPLETED` handler (the eventual-consistency backstop,
 * `confirmFromWebhookResource()`) — one implementation, not two that could
 * drift. See docs/architecture/03-integrations.md's PayPal section, point 6,
 * and docs/architecture/02-api-contract.md's
 * `POST /api/v1/orders/{order}/paypal-capture` section.
 *
 * Depends on the concrete {@see PayPalPaymentGateway} directly, never the
 * generic active-gateway `PaymentGateway` interface — this service is
 * inherently PayPal-only, same reasoning as the webhook controllers (see
 * docs/architecture/03-integrations.md's PayPal section, point 3).
 */
class PayPalCaptureService
{
    public function __construct(
        private readonly PayPalPaymentGateway $gateway,
        private readonly PaymentConfirmationService $confirmation,
    ) {}

    /**
     * Called synchronously from `OrderController::paypalCapture()`
     * immediately after the buyer approves in PayPal's JS SDK. Idempotent:
     * if the order's charge `Payment` is already `succeeded` (a replay, or
     * the webhook backstop already processed it), this is a no-op — no
     * second PayPal capture call.
     */
    public function captureAndConfirm(Order $order, string $idempotencyKey): PayPalCaptureOutcome
    {
        $payment = $this->chargePayment($order);

        if ($payment->status === PaymentTransactionStatus::Succeeded) {
            return PayPalCaptureOutcome::succeeded();
        }

        try {
            $result = $this->gateway->captureOrder($payment->gateway_reference, $idempotencyKey);
        } catch (PayPalCaptureDeclinedException $e) {
            $this->recordDecline($payment->id, $order->id, $e->rawResponse);

            return PayPalCaptureOutcome::declined($e->getMessage());
        }

        $this->recordSuccess($payment->id, $order->id, PaymentMethod::fromPayPal($result->paymentSourceType), $result->captureId, $result->rawResponse);

        return PayPalCaptureOutcome::succeeded();
    }

    /**
     * Called from `PayPalWebhookController`'s `PAYMENT.CAPTURE.COMPLETED`
     * handler — applies the identical confirm logic directly from the
     * webhook's own embedded `resource` (the capture object), no follow-up
     * PayPal API call needed (the capture already happened). Any exception
     * here must propagate uncaught to the webhook controller — see that
     * class's docblock for why the cache "mark handled" write must never
     * happen before this completes successfully.
     *
     * @param  array<string, mixed>  $captureResource
     */
    public function confirmFromWebhookResource(array $captureResource): void
    {
        $paypalOrderId = $captureResource['supplementary_data']['related_ids']['order_id'] ?? null;
        $captureId = $captureResource['id'] ?? null;
        $status = $captureResource['status'] ?? null;

        if (! is_string($paypalOrderId) || ! is_string($captureId) || ! is_string($status)) {
            Log::warning('PayPal webhook: PAYMENT.CAPTURE.COMPLETED resource missing expected fields.', ['resource' => $captureResource]);

            return;
        }

        if ($status !== 'COMPLETED') {
            // Shouldn't happen for this specific event type, but this is a
            // backstop over external data — log and no-op rather than throw,
            // same "unexpected-but-not-actionable webhook payload" posture
            // as the rest of this file's null-guard branches.
            Log::warning('PayPal webhook: PAYMENT.CAPTURE.COMPLETED resource with a non-COMPLETED status.', ['status' => $status, 'capture_id' => $captureId]);

            return;
        }

        $paymentSource = $captureResource['payment_source'] ?? null;
        $paymentSourceType = is_array($paymentSource) ? array_key_first($paymentSource) : null;

        if (! is_string($paymentSourceType)) {
            throw new RuntimeException("PayPal PAYMENT.CAPTURE.COMPLETED webhook resource for capture \"{$captureId}\" carried no payment_source.");
        }

        // Resolved before the transaction, same "throw before any lock is
        // held" posture as StripeWebhookController — an unmapped
        // payment_source type must fail loudly (and leave the webhook
        // event unmarked, so redelivery reprocesses it once the mapping gap
        // is fixed), not silently write a wrong/placeholder value.
        $method = PaymentMethod::fromPayPal($paymentSourceType);

        DB::transaction(function () use ($paypalOrderId, $captureId, $method, $captureResource): void {
            $payment = Payment::query()
                ->where('gateway_reference', $paypalOrderId)
                ->where('type', PaymentType::Charge)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                Log::warning('PayPal webhook: PAYMENT.CAPTURE.COMPLETED for an unknown gateway_reference.', ['paypal_order_id' => $paypalOrderId]);

                return;
            }

            // Replay-safety backstop — already processed by the synchronous
            // capture endpoint (the primary path) or a prior delivery of
            // this same event.
            if ($payment->status === PaymentTransactionStatus::Succeeded) {
                return;
            }

            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->first();

            if ($order === null) {
                Log::warning('PayPal webhook: PAYMENT.CAPTURE.COMPLETED for a payment with no matching order.', ['payment_id' => $payment->id]);

                return;
            }

            $payment->forceFill([
                'status' => PaymentTransactionStatus::Succeeded,
                'method' => $method,
                'gateway_capture_reference' => $captureId,
                'raw_response' => $captureResource,
            ])->save();

            $this->confirmation->confirm($order);
        });
    }

    /**
     * `PAYMENT.CAPTURE.DENIED` webhook backstop — mirrors
     * `StripeWebhookController::handlePaymentIntentFailed()`'s posture
     * (booking hold left untouched, continues toward its own natural TTL
     * expiry). Looked up by the PayPal Order id embedded in the webhook
     * resource, since no `Payment`/`Order` id is otherwise known at this
     * call site — called from `PayPalWebhookController`, never from the
     * synchronous capture endpoint (which already handles its own decline
     * path via `captureAndConfirm()`/`recordDecline()`).
     *
     * @param  array<string, mixed>  $resource
     */
    public function markDeniedByWebhook(string $paypalOrderId, array $resource): void
    {
        DB::transaction(function () use ($paypalOrderId, $resource): void {
            $payment = Payment::query()
                ->where('gateway_reference', $paypalOrderId)
                ->where('type', PaymentType::Charge)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                Log::warning('PayPal webhook: PAYMENT.CAPTURE.DENIED for an unknown gateway_reference.', ['paypal_order_id' => $paypalOrderId]);

                return;
            }

            // Never downgrade a settled row — either already marked failed
            // by a prior delivery, or already succeeded (the synchronous
            // capture endpoint won the race before this backstop arrived).
            if ($payment->status !== PaymentTransactionStatus::Pending) {
                return;
            }

            $payment->forceFill(['status' => PaymentTransactionStatus::Failed, 'raw_response' => $resource])->save();

            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->first();

            $order?->forceFill(['status' => OrderStatus::PaymentFailed, 'payment_status' => PaymentStatus::Failed])->save();
        });
    }

    private function chargePayment(Order $order): Payment
    {
        $payment = $order->payments()->where('type', PaymentType::Charge)->latest('id')->first();

        abort_if($payment === null, 500);

        return $payment;
    }

    /**
     * @param  array<string, mixed>  $rawResponse
     */
    private function recordSuccess(int $paymentId, int $orderId, PaymentMethod $method, string $captureId, array $rawResponse): void
    {
        DB::transaction(function () use ($paymentId, $orderId, $method, $captureId, $rawResponse): void {
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->first();

            abort_if($payment === null, 500);

            if ($payment->status === PaymentTransactionStatus::Succeeded) {
                // Lost the race against the webhook backstop already
                // processing this same order — nothing left to do.
                return;
            }

            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

            abort_if($order === null, 500);

            $payment->forceFill([
                'status' => PaymentTransactionStatus::Succeeded,
                'method' => $method,
                'gateway_capture_reference' => $captureId,
                'raw_response' => $rawResponse,
            ])->save();

            $this->confirmation->confirm($order);
        });
    }

    /**
     * @param  array<string, mixed>  $rawResponse
     */
    private function recordDecline(int $paymentId, int $orderId, array $rawResponse): void
    {
        DB::transaction(function () use ($paymentId, $orderId, $rawResponse): void {
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->first();

            abort_if($payment === null, 500);

            if ($payment->status !== PaymentTransactionStatus::Pending) {
                // Already resolved (succeeded via the webhook backstop
                // racing ahead of this response, or already marked failed
                // by a prior attempt) — never downgrade a settled row.
                return;
            }

            $payment->forceFill(['status' => PaymentTransactionStatus::Failed, 'raw_response' => $rawResponse])->save();

            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

            $order?->forceFill(['status' => OrderStatus::PaymentFailed, 'payment_status' => PaymentStatus::Failed])->save();
        });
    }
}
