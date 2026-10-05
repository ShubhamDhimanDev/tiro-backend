<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGateway;
use Stripe\StripeClient;

/**
 * One of two {@see PaymentGateway} implementors (alongside
 * {@see PayPalPaymentGateway}) — Stripe covers cards,
 * Apple Pay, Google Pay, and native Afterpay in one integration via the
 * Payment Element (`automatic_payment_methods`). See
 * docs/architecture/03-integrations.md item 3.
 *
 * Not `final` — call sites that must always talk to Stripe specifically,
 * regardless of which gateway is currently *active*
 * (`App\Http\Controllers\Api\V1\Webhooks\StripeWebhookController`,
 * `App\Services\Payments\PaymentGatewayResolver`) type-hint this concrete
 * class rather than the `PaymentGateway` interface, per
 * docs/architecture/03-integrations.md's PayPal section, point 3 — which
 * means a test double must be able to extend it (`Tests\Support\FakeStripePaymentGateway`)
 * to satisfy that type when bound in the container.
 */
class StripePaymentGateway implements PaymentGateway
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function createPaymentIntent(int $amountCents, string $currency, array $metadata): PaymentIntentResult
    {
        $intent = $this->stripe->paymentIntents->create([
            'amount' => $amountCents,
            'currency' => $currency,
            'metadata' => $metadata,
            'automatic_payment_methods' => ['enabled' => true],
        ]);

        return new PaymentIntentResult($intent->id, (string) $intent->client_secret, $intent->status);
    }

    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntentResult
    {
        $intent = $this->stripe->paymentIntents->retrieve($paymentIntentId);

        return new PaymentIntentResult($intent->id, (string) $intent->client_secret, $intent->status);
    }

    public function createRefund(string $paymentIntentId, ?int $amountCents, string $idempotencyKey): RefundResult
    {
        $params = ['payment_intent' => $paymentIntentId];

        if ($amountCents !== null) {
            $params['amount'] = $amountCents;
        }

        $refund = $this->stripe->refunds->create($params, ['idempotency_key' => $idempotencyKey]);

        return new RefundResult($refund->id, $refund->status, (int) $refund->amount);
    }

    public function retrievePaymentMethod(string $paymentMethodId): PaymentMethodResult
    {
        $paymentMethod = $this->stripe->paymentMethods->retrieve($paymentMethodId);

        // Only ever populated for `type === 'card'` — see
        // PaymentMethodResult's docblock. Guarded on `type` rather than
        // just chaining `?->card?->wallet?->type` so a non-card
        // PaymentMethod (whose `card` hash Stripe omits entirely, not just
        // nulls) never triggers the SDK's "undefined property" notice.
        $walletType = $paymentMethod->type === 'card' ? $paymentMethod->card?->wallet?->type : null;

        return new PaymentMethodResult($paymentMethod->type, $walletType);
    }
}
