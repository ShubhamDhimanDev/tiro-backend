<?php

namespace App\Models;

use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per (tyre variant, stock location) — the raw stock ledger that
 * zone-level availability is summed from.
 *
 * @property int $id
 * @property int $tyre_variant_id
 * @property int $stock_location_id
 * @property int $qty_on_hand
 * @property int $qty_reserved
 * @property int $reorder_point
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tyre_variant_id', 'stock_location_id', 'qty_on_hand', 'qty_reserved', 'reorder_point'])]
class InventoryItem extends Model
{
    /** @use HasFactory<InventoryItemFactory> */
    use HasFactory;

    /**
     * Get the tyre variant this stock row is for.
     *
     * @return BelongsTo<TyreVariant, $this>
     */
    public function tyreVariant(): BelongsTo
    {
        return $this->belongsTo(TyreVariant::class);
    }

    /**
     * Get the stock location this stock row is held at.
     *
     * @return BelongsTo<StockLocation, $this>
     */
    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty_on_hand' => 'integer',
            'qty_reserved' => 'integer',
            'reorder_point' => 'integer',
        ];
    }
}
