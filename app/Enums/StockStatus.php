<?php

namespace App\Enums;

use App\Services\Catalogue\ZoneStockCalculator;

/**
 * Zone-scoped stock availability for a `TyreVariant`, computed on demand by
 * {@see ZoneStockCalculator} — never persisted.
 * See docs/architecture/02-api-contract.md's availability endpoint.
 */
enum StockStatus: string
{
    case InStock = 'in_stock';
    case Limited = 'limited';
    case OutOfStock = 'out_of_stock';

    /** Product is real/indexable, but no `StockLocation` linked to this zone carries it. */
    case UnavailableInZone = 'unavailable_in_zone';
}
