<?php

namespace Tests\Support;

use App\Services\Payments\PaymentIntentResult;
use App\Services\Payments\PaymentMethodResult;
use App\Services\Payments\PayPalCaptureDeclinedException;
use App\Services\Payments\PayPalCaptureResult;
use App\Services\Payments\PayPalPaymentGateway;
use App\Services\Payments\RefundResult;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * In-memory {@see PayPalPaymentGateway} test double — bind via
 * `$this->app->instance(PayPalPaymentGateway::class, new FakePayPalPaymentGateway())`
 * so tests exercising `PayPalCaptureService`/`PayPalWebhookController`/
 * `RefundService` (PayPal branch) never need live PayPal credentials. Same
 * "extends the concrete class" reasoning as
 * {@see FakeStripePaymentGateway} — those call sites
 * type-hint `PayPalPaymentGateway` directly.
 */
class FakePayPalPaymentGateway extends PayPalPaymentGateway
{
    /** @var list<array{id: string, amountCents: int, currency: string, metadata: array<string, string>}> */
    public array $createdOrders = [];

    /** @var list<string> */
    public array $retrievedOrderIds = [];

    /** @var list<array{id: string, captureId: string, amountCents: int|null, idempotencyKey: string}> */
    public array $createdRefunds = [];

    /** @var list<array{orderId: string, idempotencyKey: string}> */
    public array $capturedOrders = [];

    /** @var array<string, string> default payment_source type returned by captureOrder(), keyed by PayPal order id */
    public array $paymentSourceTypeOverrides = [];

    /**
     * Set `$this->declineNextCapture[$paypalOrderId] = 'reason'` before the
     * call to simulate a PayPal-reported decline instead of a successful
     * capture.
     *
     * @var array<string, string>
     */
    public array $declineNextCapture = [];

    public function __construct() {}

    public function createPaymentIntent(int $amountCents, string $currency, array $metadata): PaymentIntentResult
    {
        $id = 'EC-'.Str::upper(Str::random(17));

        $this->createdOrders[] = compact('id', 'amountCents', 'currency', 'metadata');

        return new PaymentIntentResult($id, null, 'CREATED');
    }

    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntentResult
    {
        $this->retrievedOrderIds[] = $paymentIntentId;

        return new PaymentIntentResult($paymentIntentId, null, 'CREATED');
    }

    public function createRefund(string $paymentIntentId, ?int $amountCents, string $idempotencyKey): RefundResult
    {
        $id = 're_paypal_'.Str::random(24);

        $this->createdRefunds[] = ['id' => $id, 'captureId' => $paymentIntentId, 'amountCents' => $amountCents, 'idempotencyKey' => $idempotencyKey];

        return new RefundResult($id, 'COMPLETED', $amountCents ?? 0);
    }

    public function retrievePaymentMethod(string $paymentMethodId): PaymentMethodResult
    {
        throw new RuntimeException('FakePayPalPaymentGateway::retrievePaymentMethod() is unreachable by design — see PayPalPaymentGateway::retrievePaymentMethod()\'s docblock.');
    }

    public function captureOrder(string $paypalOrderId, string $idempotencyKey): PayPalCaptureResult
    {
        $this->capturedOrders[] = ['orderId' => $paypalOrderId, 'idempotencyKey' => $idempotencyKey];

        if (isset($this->declineNextCapture[$paypalOrderId])) {
            throw new PayPalCaptureDeclinedException($this->declineNextCapture[$paypalOrderId], ['order_id' => $paypalOrderId]);
        }

        $paymentSourceType = $this->paymentSourceTypeOverrides[$paypalOrderId] ?? 'paypal';
        $captureId = '1CA'.Str::upper(Str::random(14));

        return new PayPalCaptureResult(
            captureId: $captureId,
            status: 'COMPLETED',
            paymentSourceType: $paymentSourceType,
            rawResponse: [
                'id' => $paypalOrderId,
                'status' => 'COMPLETED',
                'payment_source' => [$paymentSourceType => new \stdClass],
                'purchase_units' => [[
                    'payments' => [
                        'captures' => [[
                            'id' => $captureId,
                            'status' => 'COMPLETED',
                        ]],
                    ],
                ]],
            ],
        );
    }
}
