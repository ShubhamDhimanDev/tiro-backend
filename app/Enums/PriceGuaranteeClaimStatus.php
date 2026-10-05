<?php

namespace App\Enums;

/**
 * `PriceGuaranteeClaim.status` — see
 * docs/architecture/05-promotions-pricing.md's "Price-guarantee claim
 * workflow" section. Deliberately human-reviewed, never auto-approved.
 */
enum PriceGuaranteeClaimStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
