<?php

namespace App\Models;

use App\Contracts\RevalidatesFrontend;
use App\Enums\Status;
use App\Enums\TyreSidewall;
use App\Services\Catalogue\ZoneStockCalculator;
use Database\Factories\TyreVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

/**
 * The actual sellable SKU — one per size — routable as the PDP entity
 * (see docs/architecture/06-open-decisions.md item 6).
 *
 * @property int $id
 * @property int $tyre_model_id
 * @property string $sku
 * @property string $slug
 * @property int $width
 * @property int $profile
 * @property int $rim_diameter
 * @property string $load_index
 * @property string $speed_rating
 * @property TyreSidewall $sidewall
 * @property string|null $ean
 * @property string|null $weight_kg
 * @property int $base_price
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $stock_status Transient, zone-scoped value — never a
 *                                     DB column. Only populated when `TyreController` resolves a `zone`
 *                                     param; see `TyreController::attachStockStatus()` and
 *                                     {@see ZoneStockCalculator}.
 */
#[Fillable([
    'tyre_model_id', 'sku', 'slug', 'width', 'profile', 'rim_diameter', 'load_index',
    'speed_rating', 'sidewall', 'ean', 'weight_kg', 'base_price', 'status',
])]
class TyreVariant extends Model implements RevalidatesFrontend
{
    /** @use HasFactory<TyreVariantFactory> */
    use HasFactory, Searchable;

    /**
     * The exact set of columns `GET /api/v1/tyres/{slug}`'s
     * `TyreVariantDetailResource` either renders directly or gates the
     * endpoint's 404-vs-200 visibility on (`status`, via
     * `TyreController::show()`'s `where('status', ...)` filter).
     * Deliberately excludes `sku`/`ean`/`weight_kg`/`base_price` — none are
     * rendered by that resource, and price is never ISR-relevant in this
     * project (kept purely client-fetched via the separate `availability`
     * endpoint, so a stale ISR page can never show a wrong price) — see
     * `App\Observers\FrontendRevalidationObserver`'s docblock.
     *
     * @var list<string>
     */
    private const ISR_RELEVANT_FIELDS = [
        'slug', 'width', 'profile', 'rim_diameter', 'load_index', 'speed_rating', 'sidewall', 'status',
    ];

    /**
     * Auto-generate `slug` at creation time when it isn't already set, so
     * seeders/factories/future admin "create" flows don't each have to
     * remember to do it. Once created, `slug` is admin-editable and is
     * never regenerated on update.
     */
    protected static function booted(): void
    {
        static::creating(function (self $variant): void {
            if (blank($variant->slug)) {
                $variant->slug = static::generateUniqueSlug($variant);
            }
        });
    }

    /**
     * Generate the `{tyre_model.slug}-{width}-{profile}-r{rim_diameter}`
     * slug for a not-yet-persisted variant.
     *
     * Two variants can legitimately share the same width/profile/rim under
     * one model (different load/speed ratings), so on collision this falls
     * back to appending `load_index`+`speed_rating`, then a numeric suffix
     * (`-2`, `-3`, ...) if that still collides.
     */
    public static function generateUniqueSlug(self $variant): string
    {
        $tyreModel = $variant->relationLoaded('tyreModel')
            ? $variant->tyreModel
            : TyreModel::query()->findOrFail($variant->tyre_model_id);

        $base = Str::slug("{$tyreModel->slug}-{$variant->width}-{$variant->profile}-r{$variant->rim_diameter}");

        if (! static::slugTaken($base)) {
            return $base;
        }

        $withRatings = Str::slug("{$base}-{$variant->load_index}{$variant->speed_rating}");

        if (! static::slugTaken($withRatings)) {
            return $withRatings;
        }

        $suffix = 2;

        do {
            $candidate = "{$withRatings}-{$suffix}";
            $suffix++;
        } while (static::slugTaken($candidate));

        return $candidate;
    }

    /**
     * Determine whether a slug is already in use by another variant.
     */
    protected static function slugTaken(string $slug): bool
    {
        return static::query()->where('slug', $slug)->exists();
    }

    /**
     * Get the tyre model (product line) this variant belongs to.
     *
     * @return BelongsTo<TyreModel, $this>
     */
    public function tyreModel(): BelongsTo
    {
        return $this->belongsTo(TyreModel::class);
    }

    /**
     * Get the per-location stock rows for this variant.
     *
     * @return HasMany<InventoryItem, $this>
     */
    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    /**
     * The Meilisearch index name — see `config/scout.php`'s
     * `meilisearch.index-settings` for the filterable/sortable attributes
     * that match `GET /api/v1/tyres`'s query params.
     */
    public function searchableAs(): string
    {
        return 'tyre_variants';
    }

    /**
     * Only index variants belonging to an active model — a draft/archived
     * model's variants shouldn't surface in search regardless of their own
     * `status`.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->status === Status::Active
            && $this->loadMissing('tyreModel')->tyreModel?->status === Status::Active;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $this->loadMissing('tyreModel.brand');

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'slug' => $this->slug,
            'width' => $this->width,
            'profile' => $this->profile,
            'rim_diameter' => $this->rim_diameter,
            'load_index' => $this->load_index,
            'speed_rating' => $this->speed_rating,
            'sidewall' => $this->sidewall->value,
            'status' => $this->status->value,
            'base_price' => $this->base_price,
            'tyre_model_id' => $this->tyre_model_id,
            'tyre_model_name' => $this->tyreModel?->name,
            'tyre_type' => $this->tyreModel?->tyre_type?->value,
            'category' => $this->tyreModel?->category?->value,
            'brand_id' => $this->tyreModel?->brand_id,
            'brand_slug' => $this->tyreModel?->brand?->slug,
            'brand_name' => $this->tyreModel?->brand?->name,
            'released_at' => $this->tyreModel?->released_at?->timestamp,
            'created_at' => $this->created_at?->timestamp,
        ];
    }

    /**
     * ISR tag for this variant's own PDP page (`content:tyre:{slug}`, not
     * `content:tyre_variant:...` — matches the storefront's `/tyres/{slug}`
     * route naming). Fires only on update/delete, and only when at least
     * one of {@see ISR_RELEVANT_FIELDS} actually changed — see
     * `TyreModel::revalidationTags()`'s identical guard/reasoning.
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
            'updated' => $this->wasChanged(self::ISR_RELEVANT_FIELDS) ? ["content:tyre:{$this->slug}"] : [],
            'deleted' => ["content:tyre:{$this->slug}"],
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
            'width' => 'integer',
            'profile' => 'integer',
            'rim_diameter' => 'integer',
            'sidewall' => TyreSidewall::class,
            'weight_kg' => 'decimal:2',
            'base_price' => 'integer',
            'status' => Status::class,
        ];
    }
}
