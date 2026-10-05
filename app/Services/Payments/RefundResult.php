<?php

namespace App\Services\Payments;

/**
 * A gateway-agnostic view of a Stripe `Refund` — see
 * {@see PaymentIntentResult}'s docblock for why this boundary exists.
 */
final class RefundResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly int $amount,
    ) {}
}
