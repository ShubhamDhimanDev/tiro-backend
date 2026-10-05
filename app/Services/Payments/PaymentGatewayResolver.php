<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGateway;
use App\Enums\PaymentGateway as PaymentGatewayEnum;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Resolves the concrete {@see PaymentGateway} implementation for an
 * already-persisted `Payment` row's own `gateway` column — deliberately
 * distinct from the `PaymentGateway::class` interface binding
 * (`App\Providers\AppServiceProvider::register()`), which resolves whichever
 * gateway is *currently active* per `config('services.payment_gateway')` and
 * is only correct for creating a brand-new payment.
 *
 * Once a merchant can flip `PAYMENT_GATEWAY` post-launch, any code touching
 * a historical `Payment` row (`App\Services\Commerce\RefundService`,
 * reconciliation/webhook code) must resolve the gateway implementation from
 * that row's own `gateway` value, never from the active-config singleton —
 * a refund against a Stripe-era order, issued after the merchant switches to
 * PayPal, must still call Stripe's Refund API with the original
 * `PaymentIntent` id. See docs/architecture/03-integrations.md's PayPal
 * section, point 3.
 *
 * A plain concrete class, not an interface — no second implementation is
 * ever needed, this is the one resolution mechanism. Bound as its own lazy
 * singleton in `AppServiceProvider`.
 */
class PaymentGatewayResolver
{
    public function __construct(private readonly Container $app) {}

    public function resolve(PaymentGatewayEnum $gateway): PaymentGateway
    {
        return match ($gateway) {
            PaymentGatewayEnum::Stripe => $this->app->make(StripePaymentGateway::class),
            PaymentGatewayEnum::PayPal => $this->app->make(PayPalPaymentGateway::class),
            PaymentGatewayEnum::Zip => throw new RuntimeException('Zip is a reserved gateway enum value with no PaymentGateway implementation yet.'),
        };
    }
}
