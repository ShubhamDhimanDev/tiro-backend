<?php

namespace App\Contracts\Payments;

use App\Services\Payments\PaymentIntentResult;
use App\Services\Payments\PaymentMethodResult;
use App\Services\Payments\RefundResult;

/**
 * The payment-processing boundary `OrderController`/`StripeWebhookController`/
 * the admin refund flow code against — never the Stripe SDK directly. Keeps
 * every real-money call site testable without live Stripe credentials (bind
 * a fake implementation in tests) and gives Zip (see
 * docs/architecture/03-integrations.md item 3) a second implementor to slot
 * in later without touching call sites.
 */
interface PaymentGateway
{
    /**
     * Create a new `PaymentIntent` for `$amountCents` (integer minor units)
     * in `$currency` (lowercase ISO code, e.g. `aud`), tagged with
     * `$metadata` (e.g. `order_id`/`booking_id`).
     *
     * @param  array<string, string>  $metadata
     */
    public function createPaymentIntent(int $amountCents, string $currency, array $metadata): PaymentIntentResult;

    /**
     * Re-fetch an existing `PaymentIntent` by id — used on an
     * `Idempotency-Key` replay of `POST /api/v1/orders`, which must never
     * create a second `PaymentIntent`, only re-surface the original
     * `client_secret` (safe, doesn't rotate).
     */
    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntentResult;

    /**
     * Refund (fully or partially, per `$amountCents`, null = full remaining
     * balance) the charge behind `$paymentIntentId`. `$idempotencyKey` is
     * client-supplied (the admin refund route's `Idempotency-Key` header)
     * and looked up by `(order_id, idempotency_key)` before any Stripe call
     * — see `RefundService::refund()` and
     * docs/architecture/02-api-contract.md's refund-flow section.
     */
    public function createRefund(string $paymentIntentId, ?int $amountCents, string $idempotencyKey): RefundResult;

    /**
     * Resolve a `PaymentMethod`'s real Stripe-reported type (and wallet
     * sub-type, if any) by id. Stripe never delivers this expanded on a
     * webhook event — see {@see PaymentMethodResult}'s docblock — so
     * `payment_intent.succeeded` handling needs this follow-up call to
     * learn the actual instrument used, since it isn't knowable at
     * `POST /api/v1/orders`-time (see `App\Enums\PaymentMethod::fromStripe()`).
     */
    public function retrievePaymentMethod(string $paymentMethodId): PaymentMethodResult;
}
