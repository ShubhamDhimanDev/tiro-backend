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
    use HasFactory;

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
