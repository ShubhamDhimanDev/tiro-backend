<?php

namespace App\Models;

use Database\Factories\StockLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A physical warehouse/depot — distinct from {@see ServiceZone}, may back
 * multiple zones via {@see ServiceZoneStockLocation}.
 *
 * @property int $id
 * @property string $name
 * @property string $address
 * @property string $lat
 * @property string $lng
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'address', 'lat', 'lng'])]
class StockLocation extends Model
{
    /** @use HasFactory<StockLocationFactory> */
    use HasFactory;

    /**
     * Get the service zones this location backs.
     *
     * @return BelongsToMany<ServiceZone, $this, ServiceZoneStockLocation>
     */
    public function serviceZones(): BelongsToMany
    {
        return $this->belongsToMany(ServiceZone::class, 'service_zone_stock_location')
            ->using(ServiceZoneStockLocation::class)
            ->withTimestamps();
    }

    /**
     * Get the stock rows held at this location.
     *
     * @return HasMany<InventoryItem, $this>
     */
    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
        ];
    }
}
