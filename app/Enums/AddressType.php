<?php

namespace App\Enums;

/**
 * `Address.type` — see docs/architecture/01-data-model.md's `Address`
 * section. Billing = fitting for MVP: `Order` points at exactly one
 * `Address` (`type = fitting`); `billing` is reserved for a future
 * separate-billing-address feature, not built this phase.
 */
enum AddressType: string
{
    case Fitting = 'fitting';
    case Billing = 'billing';
}
