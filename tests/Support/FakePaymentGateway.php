<?php

namespace Tests\Support;

use App\Contracts\Payments\PaymentGateway;
use App\Services\Payments\PaymentIntentResult;
use App\Services\Payments\PaymentMethodResult;
use App\Services\Payments\RefundResult;
use Illuminate\Support\Str;

/**
 * In-memory {@see PaymentGateway} test double — bind via
 * `$this->app->instance(PaymentGateway::class, new FakePaymentGateway())` so
 * Phase 4 tests never need live Stripe credentials. Records every call so
 * tests can assert e.g. "an Idempotency-Key replay never creates a second
 * PaymentIntent".
 */
class FakePaymentGateway implements PaymentGateway
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
