<?php

namespace App\Enums;

/**
 * `Payment.type` — a `Payment` row is either the original charge or one of
 * possibly several refunds against it. `amount` is always positive on both;
 * direction/sign is expressed by this field, never by a negative `amount`.
 * See docs/architecture/01-data-model.md's `Payment` section.
 */
enum PaymentType: string
{
    case Charge = 'charge';
    case Refund = 'refund';
}
