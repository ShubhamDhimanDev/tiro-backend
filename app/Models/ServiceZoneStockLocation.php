<?php

namespace App\Models;

use Database\Factories\ServiceZoneStockLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Pivot: which stock locations back a {@see ServiceZone}'s availability.
 * Zone-level stock for a `TyreVariant` = `SUM(InventoryItem.qty_on_hand -
 * qty_reserved)` across every `StockLocation` linked here (query itself is
 * a later call, this just models the relationship).
 *
 * @property int $id
 * @property int $service_zone_id
 * @property int $stock_location_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['service_zone_id', 'stock_location_id'])]
class ServiceZoneStockLocation extends Pivot
{
    /** @use HasFactory<ServiceZoneStockLocationFactory> */
    use HasFactory;

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = true;

    /**
     * Get the service zone side of this pivot.
     *
     * @return BelongsTo<ServiceZone, $this>
     */
    public function serviceZone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class);
    }

    /**
     * Get the stock location side of this pivot.
     *
     * @return BelongsTo<StockLocation, $this>
     */
    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }
}
