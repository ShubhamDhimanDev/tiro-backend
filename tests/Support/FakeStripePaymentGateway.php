<?php

namespace Tests\Support;

use App\Services\Payments\PaymentIntentResult;
use App\Services\Payments\PaymentMethodResult;
use App\Services\Payments\RefundResult;
use App\Services\Payments\StripePaymentGateway;
use Illuminate\Support\Str;

/**
 * In-memory {@see StripePaymentGateway} test double — bind via
 * `$this->app->instance(StripePaymentGateway::class, new FakeStripePaymentGateway())`
 * so tests exercising `StripeWebhookController`/`RefundService` (both now
 * depending on the concrete class, not the generic `PaymentGateway`
 * interface — see docs/architecture/03-integrations.md's PayPal section,
 * point 3) never need live Stripe credentials.
 *
 * Extends the concrete class (rather than merely implementing the shared
 * interface, like {@see FakePaymentGateway}) precisely because those call
 * sites now type-hint `StripePaymentGateway` directly — the container will
 * only inject an object that's actually an `instanceof` it. Deliberately
 * does not call `parent::__construct()` (no live `StripeClient` needed);
 * every method below is a full override, so the real
 * `StripePaymentGateway`'s implementation is never reached.
 *
 * Same recording/override shape as `FakePaymentGateway` — kept as a
 * separate class rather than sharing a trait, since the two are bound
 * against different container keys for different reasons and duplicating
 * ~40 lines is cheaper than a shared-behavior abstraction that would only
 * ever have two use sites.
 */
class FakeStripePaymentGateway extends StripePaymentGateway
{
    /** @var list<array{id: string, amountCents: int, currency: string, metadata: array<string, string>}> */
    public array $createdIntents = [];

    /** @var list<string> */
    public array $retrievedIntentIds = [];

    /** @var list<array{id: string, paymentIntentId: string, amountCents: int|null, idempotencyKey: string}> */
    public array $createdRefunds = [];

    /** @var array<string, string> */
    public array $intentStatusOverrides = [];

    /** @var array<string, array{type: string, walletType: string|null}> */
    public array $paymentMethodOverrides = [];

    /** @var list<string> */
    public array $retrievedPaymentMethodIds = [];

    public function __construct() {}

    public function createPaymentIntent(int $amountCents, string $currency, array $metadata): PaymentIntentResult
    {
        $id = 'pi_'.Str::random(24);

        $this->createdIntents[] = compact('id', 'amountCents', 'currency', 'metadata');

        return new PaymentIntentResult($id, "{$id}_secret_".Str::random(16), $this->intentStatusOverrides[$id] ?? 'requires_payment_method');
    }

    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntentResult
    {
        $this->retrievedIntentIds[] = $paymentIntentId;

        return new PaymentIntentResult($paymentIntentId, "{$paymentIntentId}_secret_refetched", $this->intentStatusOverrides[$paymentIntentId] ?? 'succeeded');
    }

    public function createRefund(string $paymentIntentId, ?int $amountCents, string $idempotencyKey): RefundResult
    {
        $id = 're_'.Str::random(24);

        $this->createdRefunds[] = compact('id', 'paymentIntentId', 'amountCents', 'idempotencyKey');

        return new RefundResult($id, 'succeeded', $amountCents ?? 0);
    }

    /**
     * Defaults to a plain (non-wallet) card — set
     * `$this->paymentMethodOverrides[$paymentMethodId] = ['type' => ..., 'walletType' => ...]`
     * before the call to simulate a wallet/Afterpay/etc. instrument.
     */
    public function retrievePaymentMethod(string $paymentMethodId): PaymentMethodResult
    {
        $this->retrievedPaymentMethodIds[] = $paymentMethodId;

        $override = $this->paymentMethodOverrides[$paymentMethodId] ?? ['type' => 'card', 'walletType' => null];

        return new PaymentMethodResult($override['type'], $override['walletType']);
    }
}
