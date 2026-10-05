<?php

namespace App\Models;

use App\Contracts\RevalidatesFrontend;
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
class TyreModel extends Model implements RevalidatesFrontend
{
    /** @use HasFactory<TyreModelFactory> */
    use HasFactory;

    /**
     * The exact set of columns `GET /api/v1/tyres/{slug}`'s
     * `TyreModelDetailResource` (nested under `TyreVariantDetailResource`)
     * either renders directly or gates the endpoint's 404-vs-200 visibility
     * on (`status`, via `TyreController::show()`'s `where('status', ...)`
     * filter). Deliberately excludes `brand_id`/`released_at` (not
     * rendered by that resource) and anything price/`InventoryItem`-related
     * (never ISR-cached in this project — see
     * `App\Observers\FrontendRevalidationObserver`'s docblock and this
     * model's {@see revalidationTags()}).
     *
     * @var list<string>
     */
    private const ISR_RELEVANT_FIELDS = [
        'name', 'slug', 'description', 'warranty_text', 'warranty_km',
        'service_inclusions', 'images', 'construction', 'run_flat',
        'category', 'tyre_type', 'status',
    ];

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
     * ISR tag for this model's own PDP static-content contribution. Fires
     * only on update/delete, and only when at least one of
     * {@see ISR_RELEVANT_FIELDS} actually changed — a bare price/stock-
     * adjacent save (nothing lives on this model, but mirrors
     * `TyreVariant`'s identical guard) or an irrelevant-field touch must
     * not queue a webhook. Mirrors `PromotionObserver::updated()`'s
     * `getChanges() === []` early-return pattern, scoped to this specific
     * field allowlist instead of "any change at all".
     *
     * No `default => throw` arm — see `Brand::revalidationTags()`'s
     * docblock for why (this 3-arm match is provably exhaustive, unlike the
     * genuinely-external-input case this project's usual exhaustive-enum
     * convention targets).
     *
     * @param  'created'|'updated'|'deleted'  $event
     * @return list<string>
     */
    public function revalidationTags(string $event): array
    {
        return match ($event) {
            'created' => [],
            'updated' => $this->wasChanged(self::ISR_RELEVANT_FIELDS) ? ["content:tyre_model:{$this->slug}"] : [],
            'deleted' => ["content:tyre_model:{$this->slug}"],
        };
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
