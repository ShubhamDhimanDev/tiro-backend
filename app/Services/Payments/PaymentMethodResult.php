<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGateway;

/**
 * A gateway-agnostic view of a Stripe `PaymentMethod`'s type — see
 * {@see PaymentIntentResult}'s docblock for why this boundary exists.
 *
 * `$type` is Stripe's raw top-level `PaymentMethod.type` (e.g. `card`,
 * `afterpay_clearpay`). `$walletType` is Stripe's raw
 * `PaymentMethod.card.wallet.type` (e.g. `apple_pay`, `google_pay`) — only
 * ever non-null when `$type === 'card'` and the card was presented via a
 * wallet. Stripe webhook payloads never carry a `PaymentIntent`'s
 * `payment_method` expanded (see docs.stripe.com/expand's "Expansion with
 * webhooks" note — expansion doesn't work on webhook events at all, only a
 * follow-up API call can resolve it), so
 * {@see PaymentGateway::retrievePaymentMethod()} is
 * always a real round trip, never a payload read. See
 * docs/architecture/03-integrations.md item 3.
 */
final class PaymentMethodResult
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $walletType,
    ) {}
}
