<?php

namespace App\Models;

use Database\Factories\SizePriceTierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The total price (cents, GST-inclusive, fitting included) for buying
 * `quantity` tyres from a {@see SizePriceBand} in one order. A total, not a
 * per-tyre price: "4 tyres cost the same as 3" is simply two equal rows.
 *
 * @property int $id
 * @property int $size_price_band_id
 * @property int $quantity
 * @property int $total_price
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['size_price_band_id', 'quantity', 'total_price'])]
class SizePriceTier extends Model
{
    /** @use HasFactory<SizePriceTierFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<SizePriceBand, $this>
     */
    public function band(): BelongsTo
    {
        return $this->belongsTo(SizePriceBand::class, 'size_price_band_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'total_price' => 'integer',
        ];
    }
}
