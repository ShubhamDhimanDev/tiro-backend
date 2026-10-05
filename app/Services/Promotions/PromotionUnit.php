<?php

namespace App\Services\Promotions;

/**
 * One individual pooled cart/booking unit — a line of `{ tyre_variant_id,
 * quantity: 4 }` expands to 4 of these. See
 * docs/architecture/05-promotions-pricing.md's "4-for-3 mechanics" section:
 * "Pool, don't group per-SKU: collect every individual cart/booking unit...
 * across all line items."
 *
 * `lineIndex` is the unit's originating position in the caller's `items`
 * collection (0-based) — used to attribute a promotion's discount back to
 * the correct `PricingLine`/`OrderLineItem` once the evaluation algorithm
 * has decided which units are discounted.
 */
final class PromotionUnit
{
    public function __construct(
        public readonly int $lineIndex,
        public readonly int $tyreVariantId,
        public readonly int $unitPrice,
    ) {}
}
