<?php

namespace App\Enums;

/**
 * `Promotion.type` — see docs/architecture/01-data-model.md's "Promotions &
 * pricing" section and docs/architecture/05-promotions-pricing.md for the
 * per-type discount mechanics.
 *
 * `FourForThree` is the only type this phase gives a fully concrete,
 * schema-backed algorithm for (pool-sort-descending-then-consecutive-
 * group-of-4, last/cheapest unit in each complete group discounted 100%) —
 * see `App\Services\Promotions\PromotionEvaluationService`. `Bundle` and
 * `BuyXGetY` are accepted here because the data model's field list names
 * them, but the schema has no `buy_quantity`/`get_quantity`/`get_discount`
 * columns to configure them differently from `FourForThree`, and
 * 05-promotions-pricing.md explicitly pairs "four_for_three/bundle" as
 * sharing "the grouping algorithm below" — so both route through the exact
 * same pooled grouping calculation as `FourForThree` for now. Flagged as a
 * genuine spec gap in the Phase 5 handback, not a silent invention: a
 * genuinely configurable buy-X-get-Y needs new columns, not built this
 * phase.
 */
enum PromotionType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
    case Bundle = 'bundle';
    case BuyXGetY = 'buy_x_get_y';
    case FourForThree = 'four_for_three';
}
