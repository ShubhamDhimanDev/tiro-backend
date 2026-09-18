<?php

namespace App\Services\Catalogue;

use App\Enums\StockStatus;
use App\Models\InventoryItem;
use App\Models\ServiceZone;
use Illuminate\Support\Collection;

/**
 * Computes zone-scoped stock availability for `TyreVariant` SKUs:
 * `SUM(InventoryItem.qty_on_hand - qty_reserved)` across every
 * `StockLocation` linked to a {@see ServiceZone} via
 * `ServiceZoneStockLocation` — see docs/architecture/02-api-contract.md's
 * availability endpoint.
 */
class ZoneStockCalculator
{
    /**
     * Available-units cutoff below which stock is reported `limited` rather
     * than `in_stock`. No business rule for this threshold exists yet —
     * placeholder pending product sign-off, same status as the availability
     * endpoint's `service_fee`.
     */
    private const LOW_STOCK_THRESHOLD = 3;

    /**
     * @param  iterable<int, int>  $tyreVariantIds
     * @return array<int, StockStatus> Keyed by `tyre_variant_id`.
     */
    public function forVariants(iterable $tyreVariantIds, ServiceZone $zone): array
    {
        $ids = collect($tyreVariantIds)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $stockLocationIds = $zone->stockLocations()->pluck('stock_locations.id');

        if ($stockLocationIds->isEmpty()) {
            return $ids->mapWithKeys(fn (int $id) => [$id => StockStatus::UnavailableInZone])->all();
        }

        /** @var Collection<int, int> $availableQtyByVariantId */
        $availableQtyByVariantId = InventoryItem::query()
            ->whereIn('tyre_variant_id', $ids)
            ->whereIn('stock_location_id', $stockLocationIds)
            ->selectRaw('tyre_variant_id, SUM(qty_on_hand - qty_reserved) as available_qty')
            ->groupBy('tyre_variant_id')
            ->pluck('available_qty', 'tyre_variant_id');

        return $ids->mapWithKeys(fn (int $id) => [
            $id => $this->statusFor(
                isCarriedInZone: $availableQtyByVariantId->has($id),
                availableQty: (int) ($availableQtyByVariantId[$id] ?? 0),
            ),
        ])->all();
    }

    public function forVariant(int $tyreVariantId, ServiceZone $zone): StockStatus
    {
        return $this->forVariants([$tyreVariantId], $zone)[$tyreVariantId];
    }

    private function statusFor(bool $isCarriedInZone, int $availableQty): StockStatus
    {
        if (! $isCarriedInZone) {
            return StockStatus::UnavailableInZone;
        }

        return match (true) {
            $availableQty <= 0 => StockStatus::OutOfStock,
            $availableQty <= self::LOW_STOCK_THRESHOLD => StockStatus::Limited,
            default => StockStatus::InStock,
        };
    }
}
