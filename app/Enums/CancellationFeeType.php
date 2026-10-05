<?php

namespace App\Enums;

/**
 * `CancellationPolicy.fee_type` — see docs/architecture/01-data-model.md's
 * `CancellationPolicy` section. `flat` requires `fee_amount` (cents);
 * `percent` requires `fee_percent`.
 */
enum CancellationFeeType: string
{
    case Flat = 'flat';
    case Percent = 'percent';
}
