<?php

namespace App\Models;

use App\Contracts\RevalidatesFrontend;
use App\Enums\BrandTier;
use App\Enums\Status;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $logo_path
 * @property string|null $country_of_origin
 * @property BrandTier|null $tier
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'logo_path', 'country_of_origin', 'tier', 'status'])]
class Brand extends Model implements RevalidatesFrontend
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    /**
     * Get the tyre models sold under this brand.
     *
     * @return HasMany<TyreModel, $this>
     */
    public function tyreModels(): HasMany
    {
        return $this->hasMany(TyreModel::class);
    }

    /**
     * ISR tags for the storefront's brand surfaces: `content:brand:list` for
     * the home brands band and `/brands` index (so a new brand or a newly
     * uploaded logo shows up there on every event, creation included), and
     * `content:brand:{slug}` for the brand's own page, which has no
     * previously-cached page to invalidate on create — see
     * `App\Observers\FrontendRevalidationObserver`.
     *
     * No `default => throw` arm (unlike this project's usual exhaustive-enum
     * convention, e.g. `App\Enums\VehicleFitmentConfidence`): that
     * convention exists for genuinely external/untyped input (a Stripe
     * webhook string, say — see `PaymentMethod::fromStripeType()`) where
     * phpstan can't prove the match exhaustive. Here `$event` only ever
     * comes from `FrontendRevalidationObserver`'s own three hardcoded call
     * sites, so phpstan *can* prove this 3-arm match exhaustive against the
     * `@param` type below — a trailing `default => throw` would be
     * genuinely unreachable dead code, which phpstan correctly flags.
     *
     * @param  'created'|'updated'|'deleted'  $event
     * @return list<string>
     */
    public function revalidationTags(string $event): array
    {
        return match ($event) {
            'created' => ['content:brand:list'],
            'updated', 'deleted' => ['content:brand:list', "content:brand:{$this->slug}"],
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
            'tier' => BrandTier::class,
            'status' => Status::class,
        ];
    }
}
