<?php

namespace App\Models;

use App\Enums\PromotionEligibilityScope;
use Database\Factories\PromotionEligibilityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Scopes a {@see Promotion} to a brand/tyre_model/tyre_variant/category,
 * optionally further restricted to a single service zone.
 *
 * `scope_id` is stored as a string, not a plain integer FK, because it is
 * genuinely polymorphic across a heterogeneous set of targets: for
 * `brand`/`tyre_model`/`tyre_variant` it holds that table's numeric id (as a
 * string); for `category` there is no lookup table to point an FK at
 * (`App\Enums\TyreCategory` is a fixed string enum with no rows anywhere —
 * see docs/architecture/01-data-model.md's Catalogue section), so it holds
 * the enum's raw string value instead (e.g. `"suv"`), mirroring how
 * `DurationRule.key` already stores `TyreCategory` values as plain strings.
 * This is a genuine gap in the original field sketch, resolved here — see
 * the Phase 5 handback.
 *
 * @property int $id
 * @property int $promotion_id
 * @property PromotionEligibilityScope $scope
 * @property string $scope_id
 * @property int|null $service_zone_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['promotion_id', 'scope', 'scope_id', 'service_zone_id'])]
class PromotionEligibility extends Model
{
    /** @use HasFactory<PromotionEligibilityFactory> */
    use HasFactory;

    /**
     * Get the promotion this eligibility row belongs to.
     *
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /**
     * Get the zone this eligibility row is restricted to, if any (null =
     * applies in every zone).
     *
     * @return BelongsTo<ServiceZone, $this>
     */
    public function serviceZone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class);
    }

    /**
     * Determine whether `$variant` (with `tyreModel`/`tyreModel.brand`
     * loaded) matches this eligibility row's scope, independent of zone.
     */
    public function matchesVariant(TyreVariant $variant): bool
    {
        return match ($this->scope) {
            PromotionEligibilityScope::Brand => (string) $variant->tyreModel?->brand_id === $this->scope_id,
            PromotionEligibilityScope::TyreModel => (string) $variant->tyre_model_id === $this->scope_id,
            PromotionEligibilityScope::TyreVariant => (string) $variant->id === $this->scope_id,
            PromotionEligibilityScope::Category => $variant->tyreModel?->category->value === $this->scope_id,
        };
    }

    /**
     * Determine whether this eligibility row applies in `$serviceZoneId` —
     * unscoped (`service_zone_id = null`) rows apply everywhere.
     */
    public function matchesZone(?int $serviceZoneId): bool
    {
        return $this->service_zone_id === null || $this->service_zone_id === $serviceZoneId;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => PromotionEligibilityScope::class,
        ];
    }
}
