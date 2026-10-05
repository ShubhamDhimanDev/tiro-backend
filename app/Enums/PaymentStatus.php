<?php

namespace App\Enums;

/**
 * `Order.payment_status` — money state only, denormalized from the sum of
 * this order's `Payment` rows. See docs/architecture/01-data-model.md's
 * `Order` section.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
}
