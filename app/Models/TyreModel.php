<?php

namespace App\Models;

use App\Enums\Status;
use App\Enums\TyreCategory;
use App\Enums\TyreConstruction;
use App\Enums\TyreType;
use Database\Factories\TyreModelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Canonical product line (e.g. "Bridgestone Turanza T005") — groups the
 * sellable {@see TyreVariant} SKUs under one shared description/warranty.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $name
 * @property string $slug
 * @property TyreCategory $category
 * @property TyreType $tyre_type
 * @property TyreConstruction $construction
 * @property bool $run_flat
 * @property string|null $description
 * @property string|null $warranty_text
 * @property int|null $warranty_km
 * @property array<int, string>|null $service_inclusions
 * @property Carbon|null $released_at
 * @property array<int, string>|null $images
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'brand_id', 'name', 'slug', 'category', 'tyre_type', 'construction', 'run_flat',
    'description', 'warranty_text', 'warranty_km', 'service_inclusions', 'released_at',
    'images', 'status',
])]
class TyreModel extends Model
{
    /** @use HasFactory<TyreModelFactory> */
    use HasFactory;

    /**
     * Get the brand this model is sold under.
     *
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Get the sellable size variants for this model.
     *
     * @return HasMany<TyreVariant, $this>
     */
    public function tyreVariants(): HasMany
    {
        return $this->hasMany(TyreVariant::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => TyreCategory::class,
            'tyre_type' => TyreType::class,
            'construction' => TyreConstruction::class,
            'run_flat' => 'boolean',
            'service_inclusions' => 'array',
            'released_at' => 'date',
            'images' => 'array',
            'status' => Status::class,
        ];
    }
}
