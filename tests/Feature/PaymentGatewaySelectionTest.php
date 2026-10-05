<?php

use App\Contracts\Payments\PaymentGateway;
use App\Services\Payments\PayPalPaymentGateway;
use App\Services\Payments\StripePaymentGateway;

/**
 * `config('services.payment_gateway')` — see
 * docs/architecture/03-integrations.md's PayPal section, point 1: an
 * unrecognized value is a hard `RuntimeException`, never a silent fallback
 * to Stripe. `App\Providers\AppServiceProvider::validatePaymentGatewaySelection()`
 * validates this eagerly at boot; the `PaymentGateway::class` singleton
 * factory's own `match` is defense-in-depth for the same rule.
 */
it('resolves the Stripe implementation when PAYMENT_GATEWAY=stripe', function () {
    config(['services.payment_gateway' => 'stripe']);

    expect(app(PaymentGateway::class))->toBeInstanceOf(StripePaymentGateway::class);
});

it('resolves the PayPal implementation when PAYMENT_GATEWAY=paypal', function () {
    config(['services.payment_gateway' => 'paypal']);

    expect(app(PaymentGateway::class))->toBeInstanceOf(PayPalPaymentGateway::class);
});

it('throws for an unrecognized PAYMENT_GATEWAY value rather than silently defaulting to Stripe', function () {
    config(['services.payment_gateway' => 'bogus']);

    expect(fn () => app(PaymentGateway::class))->toThrow(RuntimeException::class);
});

it('throws for the reserved, unbuilt Zip value', function () {
    config(['services.payment_gateway' => 'zip']);

    expect(fn () => app(PaymentGateway::class))->toThrow(RuntimeException::class);
});

it('never constructs PayPalPaymentGateway when Stripe is active', function () {
    config(['services.payment_gateway' => 'stripe']);

    app(PaymentGateway::class);

    expect(app()->resolved(PayPalPaymentGateway::class))->toBeFalse();
});

it('never constructs StripePaymentGateway when PayPal is active', function () {
    config(['services.payment_gateway' => 'paypal']);

    app(PaymentGateway::class);

    expect(app()->resolved(StripePaymentGateway::class))->toBeFalse();
});
