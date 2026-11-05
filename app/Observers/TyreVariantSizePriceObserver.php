<?php

namespace App\Observers;

use App\Models\TyreVariant;
use App\Services\Commerce\SizePricingService;

/**
 * Keeps a variant's `base_price` equal to its size band's 1-tyre price
 * whenever the variant is created or its size/price is edited (catalogue
 * import, admin edit), so a new SKU never shows a stale brand-specific
 * price. Sizes outside every band are left alone.
 */
class TyreVariantSizePriceObserver
{
    public function __construct(private readonly SizePricingService $sizePricing) {}

    public function saving(TyreVariant $variant): void
    {
        if ($variant->exists && ! $variant->isDirty(['rim_diameter', 'base_price'])) {
            return;
        }

        $price = $this->sizePricing->singleTyrePrice($variant->rim_diameter);

        if ($price !== null) {
            $variant->base_price = $price;
        }
    }
}
