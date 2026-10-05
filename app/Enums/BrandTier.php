<?php

namespace App\Enums;

/**
 * `Brand.tier` — brand-level market positioning used by the redesigned
 * listing's tier badges/picks. Nullable on the brand: unclassified brands
 * simply have no tier.
 */
enum BrandTier: string
{
    case Premium = 'premium';
    case Mid = 'mid';
    case Budget = 'budget';
}
