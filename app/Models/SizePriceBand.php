<?php

namespace App\Models;

use App\Enums\Status;
use App\Services\Commerce\SizePricingService;
use Database\Factories\SizePriceBandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A rim-diameter band (inclusive) priced by size alone, whatever the brand.
 * Its {@see SizePriceTier} rows hold the total price for buying 1, 2, 3…
 * tyres of any size in the band. See {@see SizePricingService}.
 *
 * @property int $id
 * @property string $name
 * @property int $rim_min
 * @property int $rim_max
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'rim_min', 'rim_max', 'status'])]
class SizePriceBand extends Model
{
    /** @use HasFactory<SizePriceBandFactory> */
    use HasFactory;

    /**
     * @return HasMany<SizePriceTier, $this>
     */
    public function tiers(): HasMany
    {
        return $this->hasMany(SizePriceTier::class)->orderBy('quantity');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rim_min' => 'integer',
            'rim_max' => 'integer',
            'status' => Status::class,
        ];
    }
}
