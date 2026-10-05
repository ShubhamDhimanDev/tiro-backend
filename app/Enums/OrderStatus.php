<?php

namespace App\Enums;

/**
 * `Order.status` — overall business lifecycle, distinct from
 * `PaymentStatus` (money state only) — see docs/architecture/01-data-model.md's
 * `Order` section: "two different concerns, don't collapse them".
 *
 * `RefundRequired` exists for one specific edge case: a Stripe
 * `payment_intent.succeeded` webhook arrives after the linked `Booking`'s
 * hold has already expired/been cancelled — the customer paid for a slot
 * that's no longer theirs. See
 * docs/architecture/02-api-contract.md's webhook handler section.
 */
enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case PaymentFailed = 'payment_failed';
    case RefundRequired = 'refund_required';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
}
