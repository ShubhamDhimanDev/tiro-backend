<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGateway;
use App\Enums\PaymentMethod;
use PaypalServerSdkLib\Exceptions\ErrorException;
use PaypalServerSdkLib\Models\AmountWithBreakdown;
use PaypalServerSdkLib\Models\Builders\OrderRequestBuilder;
use PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder;
use PaypalServerSdkLib\Models\Builders\RefundRequestBuilder;
use PaypalServerSdkLib\Models\Money;
use PaypalServerSdkLib\PaypalServerSdkClient;
use RuntimeException;

/**
 * One of two {@see PaymentGateway} implementors (alongside
 * {@see StripePaymentGateway}) — a thin wrapper over
 * `paypal/paypal-server-sdk`'s Orders v2/Payments v2 REST APIs, same
 * "thin wrapper over an official SDK" shape as `StripePaymentGateway`. See
 * docs/architecture/03-integrations.md's PayPal section.
 *
 * Not `final` for the same testability reason as `StripePaymentGateway` —
 * see that class's docblock.
 *
 * `$this->client` handles OAuth (client-credentials grant) and its own
 * token caching internally per request — see
 * `PaypalServerSdkLib\Authentication\ClientCredentialsAuthManager` — nothing
 * bespoke needed here for the Orders/Payments API calls this class makes.
 * (Webhook *signature verification* is a materially different, raw REST
 * call this SDK doesn't cover at all — see
 * {@see PayPalWebhookSignatureVerifier}, which
 * deliberately does its own explicit, cross-request-cached OAuth token
 * fetch instead.)
 */
class PayPalPaymentGateway implements PaymentGateway
{
    public function __construct(private readonly PaypalServerSdkClient $client) {}

    /**
     * Creates a PayPal Order v2 resource (`intent = CAPTURE`) — the PayPal
     * analogue of a Stripe `PaymentIntent`. `$id` on the returned
     * {@see PaymentIntentResult} is the PayPal Order id
     * (`Payment.gateway_reference`); `$clientSecret` is always `null` — see
     * that class's docblock.
     *
     * @param  array<string, string>  $metadata
     */
    public function createPaymentIntent(int $amountCents, string $currency, array $metadata): PaymentIntentResult
    {
        $purchaseUnit = PurchaseUnitRequestBuilder::init($this->amount($amountCents, $currency))
            ->customId($metadata['order_id'] ?? null)
            ->invoiceId($metadata['booking_id'] ?? null)
            ->build();

        $orderRequest = OrderRequestBuilder::init('CAPTURE', [$purchaseUnit])->build();

        $response = $this->client->getOrdersController()->createOrder(['body' => $orderRequest]);
        $order = $response->getResult();

        return new PaymentIntentResult((string) $order->getId(), null, (string) $order->getStatus());
    }

    /**
     * Re-fetch an existing PayPal Order by id — used on an
     * `Idempotency-Key` replay of `POST /api/v1/orders`. Unlike Stripe's
     * `client_secret`, nothing here actually needs re-fetching (the Order id
     * doesn't rotate) — `OrderController` skips calling this for a PayPal
     * row and re-surfaces the stored `gateway_reference` directly instead.
     * Implemented anyway to satisfy the interface honestly (a real round
     * trip, not a fake one) for any other call site that might need it.
     */
    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntentResult
    {
        $response = $this->client->getOrdersController()->getOrder(['id' => $paymentIntentId]);
        $order = $response->getResult();

        return new PaymentIntentResult((string) $order->getId(), null, (string) $order->getStatus());
    }

    /**
     * Refunds a captured payment. `$paymentIntentId` here is the PayPal
     * **capture** id, not the Order id — `RefundService` resolves which
     * stored reference to pass based on `Payment.gateway`
     * (`gateway_capture_reference` for PayPal, never `gateway_reference` —
     * a PayPal refund targets the capture). See
     * docs/architecture/01-data-model.md's `Payment` section.
     * `$idempotencyKey` is sent as PayPal's own `PayPal-Request-Id` header —
     * its documented equivalent to Stripe's `idempotency_key` request
     * option.
     */
    public function createRefund(string $paymentIntentId, ?int $amountCents, string $idempotencyKey): RefundResult
    {
        $builder = RefundRequestBuilder::init();

        if ($amountCents !== null) {
            $builder->amount($this->money($amountCents, 'aud'));
        }

        $response = $this->client->getPaymentsController()->refundCapturedPayment([
            'captureId' => $paymentIntentId,
            'paypalRequestId' => $idempotencyKey,
            'prefer' => 'return=representation',
            'body' => $builder->build(),
        ]);

        $refund = $response->getResult();
        $refundedAmount = $refund->getAmount();

        return new RefundResult(
            (string) $refund->getId(),
            (string) $refund->getStatus(),
            $refundedAmount === null ? ($amountCents ?? 0) : self::toCents($refundedAmount->getValue()),
        );
    }

    /**
     * Unreachable by design — PayPal's `payment_source` comes back inline
     * on both the capture response and the `PAYMENT.CAPTURE.COMPLETED`
     * webhook payload; no follow-up "retrieve by id" call exists in
     * PayPal's API (unlike Stripe, whose webhook payloads never carry the
     * payment method expanded). See
     * docs/architecture/03-integrations.md's PayPal section, point 6, and
     * {@see PaymentMethod::fromPayPal()}, which resolves the
     * instrument inline instead. A future accidental call site should fail
     * loudly, not silently do something wrong.
     */
    public function retrievePaymentMethod(string $paymentMethodId): PaymentMethodResult
    {
        throw new RuntimeException('PayPalPaymentGateway::retrievePaymentMethod() is unreachable by design — see this method\'s docblock.');
    }

    /**
     * Captures a previously-created, buyer-approved PayPal Order — a
     * distinct, required server-side step PayPal's flow has that Stripe's
     * doesn't (see docs/architecture/03-integrations.md's PayPal section,
     * point 6), which is exactly why this is a PayPal-specific method
     * outside {@see PaymentGateway}'s shared interface rather than a fifth
     * interface method. Only ever called by
     * `App\Services\Commerce\PayPalCaptureService`, which type-hints this
     * concrete class directly for that reason.
     *
     * `$idempotencyKey` is sent as PayPal's own `PayPal-Request-Id` header —
     * PayPal's Capture API additionally rejects a second capture of an
     * already-captured order (`ORDER_ALREADY_CAPTURED`) on its own, defense
     * in depth alongside the caller's own `Payment.status` check.
     *
     * @throws PayPalCaptureDeclinedException on any PayPal-reported
     *                                        decline/failure (an HTTP error
     *                                        response, or a `2xx` response
     *                                        whose capture `status` isn't
     *                                        `COMPLETED`) — the caller maps
     *                                        this onto `Payment.status =
     *                                        failed`/a `422` response, never
     *                                        an uncaught `500`. Any other
     *                                        exception (network failure,
     *                                        unexpected SDK error) is left
     *                                        to propagate uncaught.
     */
    public function captureOrder(string $paypalOrderId, string $idempotencyKey): PayPalCaptureResult
    {
        try {
            $response = $this->client->getOrdersController()->captureOrder([
                'id' => $paypalOrderId,
                'paypalRequestId' => $idempotencyKey,
                'prefer' => 'return=representation',
            ]);
        } catch (ErrorException $e) {
            throw PayPalCaptureDeclinedException::fromApiError($e);
        }

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true) ?? [];

        $capture = $body['purchase_units'][0]['payments']['captures'][0] ?? null;
        $captureStatus = is_array($capture) ? ($capture['status'] ?? null) : null;

        if (! is_array($capture) || $captureStatus !== 'COMPLETED') {
            throw PayPalCaptureDeclinedException::fromCaptureResource($body, is_string($captureStatus) ? $captureStatus : (string) ($body['status'] ?? 'UNKNOWN'));
        }

        $paymentSource = $body['payment_source'] ?? null;
        $paymentSourceType = is_array($paymentSource) ? array_key_first($paymentSource) : null;

        if (! is_string($paymentSourceType)) {
            throw new RuntimeException("PayPal capture response for order \"{$paypalOrderId}\" carried no payment_source.");
        }

        return new PayPalCaptureResult(
            captureId: (string) $capture['id'],
            status: (string) $capture['status'],
            paymentSourceType: $paymentSourceType,
            rawResponse: $body,
        );
    }

    /**
     * `$currency` matches this interface's Stripe-established convention
     * (a lowercase ISO code, e.g. `aud`) — PayPal's REST API wants it
     * uppercase, converted here rather than pushing that detail onto every
     * caller.
     */
    private function amount(int $amountCents, string $currency): AmountWithBreakdown
    {
        return new AmountWithBreakdown(strtoupper($currency), $this->decimalValue($amountCents));
    }

    /**
     * Same conversion as {@see self::amount()}, but PayPal's refund request
     * body wants a `Money` object rather than `AmountWithBreakdown` —
     * identical shape, different SDK model class per endpoint.
     */
    private function money(int $amountCents, string $currency): Money
    {
        return new Money(strtoupper($currency), $this->decimalValue($amountCents));
    }

    private function decimalValue(int $amountCents): string
    {
        return number_format($amountCents / 100, 2, '.', '');
    }

    /**
     * PayPal reports money as a decimal string (e.g. `"756.00"`); this
     * project's convention is integer minor units throughout — see
     * docs/architecture/02-api-contract.md's "Money fields" convention.
     */
    private static function toCents(string $decimalValue): int
    {
        return (int) round(((float) $decimalValue) * 100);
    }
}
