<?php

namespace App\Services\Payments;

/**
 * A gateway-agnostic view of a Stripe `PaymentIntent` (or, for
 * {@see PayPalPaymentGateway}, a PayPal Order v2
 * resource), decoupling controllers/services from either SDK's raw object
 * shape — makes the `App\Contracts\Payments\PaymentGateway` boundary easy to
 * fake in tests without a live gateway connection.
 *
 * `$clientSecret` is `?string`, not `string` — PayPal has no client-secret
 * concept at all; the PayPal Order id in `$id` is the only value the
 * frontend needs (see `PayPalPaymentGateway::createPaymentIntent()`,
 * always `null`). `StripePaymentGateway` always populates a real string
 * here — non-breaking, PHP accepts `string` into a `?string`-typed
 * constructor param. Deliberately not repurposing this field to double as
 * "the PayPal order id" under a misleading name — see
 * docs/architecture/03-integrations.md's PayPal section, point 4.
 *
 * `$paymentMethodId`/`$raw` are only populated by
 * {@see StripePaymentGateway::retrievePaymentIntent()}, for the synchronous
 * confirm-after-payment path (see
 * `App\Services\Commerce\StripePaymentConfirmationService`).
 */
final class PaymentIntentResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $clientSecret,
        public readonly string $status,
        public readonly ?string $paymentMethodId = null,
        public readonly array $raw = [],
    ) {}
}
