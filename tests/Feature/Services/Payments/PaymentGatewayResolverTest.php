<?php

use App\Enums\PaymentGateway as PaymentGatewayEnum;
use App\Services\Payments\PaymentGatewayResolver;
use App\Services\Payments\PayPalPaymentGateway;
use App\Services\Payments\StripePaymentGateway;

/**
 * Resolves a concrete `PaymentGateway` implementation from an
 * already-persisted `Payment` row's own `gateway` column — see
 * docs/architecture/03-integrations.md's PayPal section, point 3, and
 * `PaymentGatewayResolver`'s own docblock for why this is a materially
 * different binding from the active-config `PaymentGateway::class`
 * interface singleton.
 */
it('resolves Stripe from the Stripe enum case, regardless of the currently-active gateway config', function () {
    config(['services.payment_gateway' => 'paypal']);

    $resolver = app(PaymentGatewayResolver::class);

    expect($resolver->resolve(PaymentGatewayEnum::Stripe))->toBeInstanceOf(StripePaymentGateway::class);
});

it('resolves PayPal from the PayPal enum case, regardless of the currently-active gateway config', function () {
    config(['services.payment_gateway' => 'stripe']);

    $resolver = app(PaymentGatewayResolver::class);

    expect($resolver->resolve(PaymentGatewayEnum::PayPal))->toBeInstanceOf(PayPalPaymentGateway::class);
});

it('throws for the reserved, unbuilt Zip gateway', function () {
    $resolver = app(PaymentGatewayResolver::class);

    expect(fn () => $resolver->resolve(PaymentGatewayEnum::Zip))->toThrow(RuntimeException::class);
});

it('is bound as a singleton', function () {
    expect(app(PaymentGatewayResolver::class))->toBe(app(PaymentGatewayResolver::class));
});
