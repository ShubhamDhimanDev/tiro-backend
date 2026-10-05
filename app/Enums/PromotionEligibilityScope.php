<?php

namespace App\Enums;

/**
 * `PromotionEligibility.scope` — which catalogue dimension `scope_id`
 * resolves against. See `PromotionEligibility`'s docblock for how
 * `scope_id` is stored for each case (it is not a plain FK integer for
 * every case — `Category` has no lookup table to point at).
 */
enum PromotionEligibilityScope: string
{
    case Brand = 'brand';
    case TyreModel = 'tyre_model';
    case TyreVariant = 'tyre_variant';
    case Category = 'category';
}
