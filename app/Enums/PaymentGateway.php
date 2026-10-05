<?php

namespace App\Enums;

/**
 * `Payment.gateway` — see docs/architecture/03-integrations.md item 3.
 * Stripe and PayPal are both fully wired (`App\Providers\AppServiceProvider`
 * binds one lazy singleton per case, selected via
 * `config('services.payment_gateway')` for new payments, or resolved per-row
 * via `App\Services\Payments\PaymentGatewayResolver` for historical ones —
 * see that class's docblock and docs/architecture/03-integrations.md's
 * PayPal section, point 3); `Zip` remains reserved for a follow-on
 * integration, not built yet.
 */
enum PaymentGateway: string
{
    case Stripe = 'stripe';
    case PayPal = 'paypal';
    case Zip = 'zip';
}
