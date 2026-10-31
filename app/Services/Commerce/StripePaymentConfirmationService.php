<?php

namespace App\Services\Commerce;

use App\Enums\PaymentGateway;
use App\Enums\PaymentMethod;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\StripePaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single implementation of "a Stripe PaymentIntent has succeeded, so mark
 * the `Payment` succeeded and confirm the `Booking`/`Order`" — shared by the
 * synchronous `POST /api/v1/orders/{order}/stripe-confirm` endpoint
 * ({@see self::confirmOrder()}, the primary path, called by the storefront
 * right after `stripe.confirmPayment()` resolves) and
 * `StripeWebhookController`'s `payment_intent.succeeded` handler
 * ({@see self::confirmSucceededIntent()}, the eventual-consistency backstop
 * for a closed tab or a delayed method such as Afterpay). Same shape as
 * {@see PayPalCaptureService}.
 *
 * Never trusts the browser: the synchronous path re-reads the PaymentIntent
 * from Stripe server-side and only confirms when Stripe itself reports
 * `succeeded`.
 *
 * Depends on the concrete {@see StripePaymentGateway}, never the active-gateway
 * `PaymentGateway` interface — see docs/architecture/03-integrations.md's
 * PayPal section, point 3.
 */
class StripePaymentConfirmationService
{
    public function __construct(
        private readonly StripePaymentGateway $gateway,
        private readonly PaymentConfirmationService $confirmation,
    ) {}

    /**
     * Pull the order's PaymentIntent from Stripe and, if it has succeeded,
     * confirm the order. Any other Stripe status (`processing`,
     * `requires_action`, `requires_payment_method`, ...) is a deliberate
     * no-op: the order stays pending and the webhook settles it later.
     * Idempotent — an already-succeeded charge makes no Stripe call at all.
     */
    public function confirmOrder(Order $order): void
    {
        $payment = $order->payments()->where('type', PaymentType::Charge)->latest('id')->first();

        abort_if($payment === null, 500);
        abort_if($payment->gateway !== PaymentGateway::Stripe, 422, __('This order was not paid through Stripe.'));

        if ($payment->status === PaymentTransactionStatus::Succeeded) {
            return;
        }

        $intent = $this->gateway->retrievePaymentIntent($payment->gateway_reference);

        if ($intent->status !== 'succeeded') {
            return;
        }

        $this->confirmSucceededIntent($intent->id, $intent->paymentMethodId, $intent->raw);
    }

    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function confirmSucceededIntent(string $paymentIntentId, ?string $paymentMethodId, array $rawResponse): void
    {
        // Resolved once, before the transaction (a real Stripe API round
        // trip — see PaymentMethodResult's docblock), rather than while
        // holding the row locks below.
        $method = $this->resolvePaymentMethod($paymentIntentId, $paymentMethodId);

        DB::transaction(function () use ($paymentIntentId, $method, $rawResponse): void {
            $payment = Payment::query()
                ->where('gateway_reference', $paymentIntentId)
                ->where('type', PaymentType::Charge)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                Log::warning('Stripe confirmation: succeeded PaymentIntent with an unknown gateway_reference.', ['payment_intent_id' => $paymentIntentId]);

                return;
            }

            // Replay-safety backstop: the synchronous endpoint and the
            // webhook both land here, whichever arrives second is a no-op.
            if ($payment->status === PaymentTransactionStatus::Succeeded) {
                return;
            }

            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->first();

            if ($order === null) {
                Log::warning('Stripe confirmation: succeeded PaymentIntent for a payment with no matching order.', ['payment_id' => $payment->id]);

                return;
            }

            $payment->forceFill([
                'status' => PaymentTransactionStatus::Succeeded,
                // Falls back to the row's existing (placeholder) value only
                // for the "shouldn't happen" case resolvePaymentMethod()
                // already logged.
                'method' => $method ?? $payment->method,
                'raw_response' => $rawResponse,
            ])->save();

            $this->confirmation->confirm($order);
        });
    }

    /**
     * Resolves the real instrument used from Stripe's own report — see
     * `PaymentMethod::fromStripe()`'s docblock for why this can't be known
     * at `Payment`-row creation time. Returns `null` only for a succeeded
     * PaymentIntent with no `payment_method` at all (not expected per
     * Stripe's contract); a present-but-unrecognized value still throws via
     * `PaymentMethod::fromStripe()`'s `default => throw`.
     */
    private function resolvePaymentMethod(string $paymentIntentId, ?string $paymentMethodId): ?PaymentMethod
    {
        if ($paymentMethodId === null || $paymentMethodId === '') {
            Log::warning('Stripe confirmation: succeeded PaymentIntent with no payment_method set.', ['payment_intent_id' => $paymentIntentId]);

            return null;
        }

        $resolved = $this->gateway->retrievePaymentMethod($paymentMethodId);

        return PaymentMethod::fromStripe($resolved->type, $resolved->walletType);
    }
}
