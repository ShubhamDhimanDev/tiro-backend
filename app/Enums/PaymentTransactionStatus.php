<?php

namespace App\Enums;

use RuntimeException;

/**
 * `Payment.status` — the individual transaction's (charge or refund) own
 * gateway-reported state. Distinct from `Order.payment_status`, which is
 * the order-level aggregate across every `Payment` row. See
 * docs/architecture/01-data-model.md's `Payment` section.
 */
enum PaymentTransactionStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Map Stripe's refund `status` string (`pending|succeeded|failed|canceled`
     * — single-`l` American spelling) onto this enum (`Cancelled`,
     * double-`l`) — the "importer boundary" pattern from
     * docs/architecture/01-data-model.md's Vehicles fragile-pattern note:
     * `$stripeStatus` is deliberately typed `string`, not this enum, so
     * phpstan can't prove the match exhaustive and the `default => throw`
     * arm stays genuinely reachable (same convention as
     * `CancellationPolicy::resolveFee()`). Shared by `RefundService` (the
     * synchronous admin refund response) and `StripeWebhookController`
     * (the async `refund.updated`/`charge.refunded` reconciliation
     * backstop) so the mapping lives in exactly one place.
     */
    public static function fromStripeStatus(string $stripeStatus): self
    {
        return match ($stripeStatus) {
            'pending' => self::Pending,
            'succeeded' => self::Succeeded,
            'failed' => self::Failed,
            'canceled' => self::Cancelled,
            default => throw new RuntimeException("Unhandled Stripe refund status \"{$stripeStatus}\"."),
        };
    }

    /**
     * Same "importer boundary" pattern as {@see self::fromStripeStatus()},
     * for PayPal's Refund `status` (`CANCELLED|PENDING|COMPLETED` — PayPal's
     * own documented vocabulary for `GET /v2/payments/refunds/{id}` and the
     * `refundCapturedPayment` response `RefundService`/`PayPalWebhookController`
     * consume). PayPal has no `FAILED` refund status of its own — an
     * outright-declined refund attempt is instead reported as an HTTP error
     * response, which `PayPalPaymentGateway::createRefund()` lets propagate
     * uncaught (same posture as Stripe SDK errors elsewhere in this
     * codebase), never reaching this mapping at all.
     */
    public static function fromPayPalRefundStatus(string $paypalStatus): self
    {
        return match ($paypalStatus) {
            'PENDING' => self::Pending,
            'COMPLETED' => self::Succeeded,
            'CANCELLED' => self::Cancelled,
            default => throw new RuntimeException("Unhandled PayPal refund status \"{$paypalStatus}\"."),
        };
    }
}
