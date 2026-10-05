<?php

namespace App\Enums;

use RuntimeException;

/**
 * `Payment.method` — the specific instrument used, as distinct from
 * `PaymentGateway` (which processor handled it). `Card`/`ApplePay`/
 * `GooglePay`/`Afterpay` route through the `stripe` gateway via Stripe's
 * Payment Element; `PayPal` is the customer paying from their PayPal
 * balance/linked account/Pay Later via the `paypal` gateway — but
 * `ApplePay`/`GooglePay` are deliberately the *same* cases when paid via
 * PayPal too (see `fromPayPal()`'s docblock: the instrument taxonomy is
 * gateway-agnostic by design, an Apple Pay payment is still "Apple Pay"
 * regardless of which gateway routed it); `Zip` is its own gateway entirely
 * (see docs/architecture/03-integrations.md item 3).
 */
enum PaymentMethod: string
{
    case Card = 'card';
    case ApplePay = 'apple_pay';
    case GooglePay = 'google_pay';
    case Afterpay = 'afterpay';
    case PayPal = 'paypal';
    case Zip = 'zip';

    /**
     * Map Stripe's raw `PaymentMethod.type` (plus, only when
     * `$stripeType === 'card'`, `PaymentMethod.card.wallet.type` for a
     * wallet-presented card) onto this enum — the "importer boundary"
     * pattern from docs/architecture/01-data-model.md's Vehicles
     * fragile-pattern note, same convention as
     * `PaymentTransactionStatus::fromStripeStatus()`: both `$stripeType`
     * and `$stripeWalletType` are deliberately typed as plain
     * `string`/`?string`, not a Stripe-side enum, so phpstan can't prove
     * either match exhaustive and the `default => throw` arms stay
     * genuinely reachable. Resolving the real instrument this way (instead
     * of guessing at `Payment`-row creation time) is required because
     * Stripe's Payment Element lets the customer choose their instrument at
     * *client-side confirmation*, strictly after `POST /api/v1/orders`
     * already created the row — see
     * `StripeWebhookController::handlePaymentIntentSucceeded()`, the only
     * caller. `Zip` is never a match here — it's a separate gateway
     * entirely, never resolved from a Stripe payload (see this enum's own
     * docblock).
     */
    public static function fromStripe(string $stripeType, ?string $stripeWalletType): self
    {
        if ($stripeType === 'card') {
            return match ($stripeWalletType) {
                null => self::Card,
                'apple_pay' => self::ApplePay,
                'google_pay' => self::GooglePay,
                default => throw new RuntimeException("Unhandled Stripe card wallet type \"{$stripeWalletType}\"."),
            };
        }

        return match ($stripeType) {
            'afterpay_clearpay' => self::Afterpay,
            default => throw new RuntimeException("Unhandled Stripe payment method type \"{$stripeType}\"."),
        };
    }

    /**
     * Map PayPal's raw `payment_source` key (the single top-level key
     * present on both the Orders v2 capture response and the
     * `PAYMENT.CAPTURE.COMPLETED` webhook's own embedded resource — see
     * `App\Services\Commerce\PayPalCaptureService`, the only two callers)
     * onto this enum — same "importer boundary" pattern as `fromStripe()`.
     * Resolved inline from that same response/payload, never a follow-up
     * API call (PayPal has no equivalent to Stripe's
     * `retrievePaymentMethod()` round trip — see
     * `PayPalPaymentGateway::retrievePaymentMethod()`'s docblock).
     *
     * `venmo` is deliberately not mapped — US-only, not reachable for an AU
     * merchant, so an unexpected `venmo` payload should throw and get
     * investigated, not be silently absorbed. See
     * docs/architecture/03-integrations.md's PayPal section, point 5.
     */
    public static function fromPayPal(string $paypalPaymentSourceType): self
    {
        return match ($paypalPaymentSourceType) {
            'paypal' => self::PayPal,
            'card' => self::Card,
            'apple_pay' => self::ApplePay,
            'google_pay' => self::GooglePay,
            default => throw new RuntimeException("Unhandled PayPal payment_source type \"{$paypalPaymentSourceType}\"."),
        };
    }
}
