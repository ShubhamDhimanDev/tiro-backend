<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGateway;

/**
 * A gateway-agnostic view of a PayPal Orders v2 capture outcome — decouples
 * `App\Services\Commerce\PayPalCaptureService` from the raw
 * `paypal/paypal-server-sdk` response shape, same reasoning as
 * {@see PaymentIntentResult}/{@see RefundResult}.
 *
 * Deliberately not part of {@see PaymentGateway} —
 * capture is a PayPal-only, two-step concept with nothing analogous on the
 * Stripe side (see docs/architecture/03-integrations.md's PayPal section,
 * point 6) — only returned by
 * {@see PayPalPaymentGateway::captureOrder()}, a
 * PayPal-specific method callers reach by depending on that concrete class
 * directly, never through the shared interface.
 */
final class PayPalCaptureResult
{
    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public readonly string $captureId,
        public readonly string $status,
        public readonly string $paymentSourceType,
        public readonly array $rawResponse,
    ) {}
}
