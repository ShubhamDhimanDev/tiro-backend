<?php

namespace App\Models;

use App\Contracts\RevalidatesFrontend;
use App\Enums\ServiceZoneType;
use App\Enums\Status;
use Database\Factories\ServiceZoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A serviceable area — either a radius around a lat/lng origin, or an
 * explicit suburb list. `origin_lat`/`origin_lng`/`radius_km` are only
 * app-level-required when `type` is {@see ServiceZoneType::Radius}; they
 * aren't DB `NOT NULL` because the requirement is conditional on `type`.
 *
 * `operating_hours` shape (locked — see docs/architecture/01-data-model.md):
 * ```json
 * {"mon": {"open": "08:00", "close": "18:00"}, ..., "sun": null}
 * ```
 * Plain zone-local `HH:mm` 24h strings, no timezone/offset. `null` (or an
 * absent key) means closed that day.
 *
 * @property int $id
 * @property string $name
 * @property int $state_id
 * @property ServiceZoneType $type
 * @property string|null $origin_lat
 * @property string|null $origin_lng
 * @property string|null $radius_km
 * @property array<string, array{open: string, close: string}|null>|null $operating_hours
 * @property int $priority
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'city_name', 'city_slug', 'state_id', 'type', 'origin_lat', 'origin_lng', 'radius_km', 'operating_hours', 'priority', 'status'])]
class ServiceZone extends Model implements RevalidatesFrontend
{
    /** @use HasFactory<ServiceZoneFactory> */
    use HasFactory;

    /**
     * Zones the storefront can actually serve: the zone is active and so is
     * its state (see {@see State::scopeActive()}). Every public coverage and
     * serviceability lookup goes through this, so the admin's active toggles
     * are the single source of truth.
     *
     * @param  Builder<ServiceZone>  $query
     * @return Builder<ServiceZone>
     */
    public function scopeServiceable(Builder $query): Builder
    {
        return $query
            ->where('status', Status::Active)
            ->whereHas('state', fn (Builder $state) => $state->active());
    }

    /**
     * Get the state this zone belongs to.
     *
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * Get the suburbs explicitly assigned to this zone (only meaningful
     * when `type` is {@see ServiceZoneType::SuburbList}).
     *
     * @return BelongsToMany<Suburb, $this, ServiceZoneSuburb>
     */
    public function suburbs(): BelongsToMany
    {
        return $this->belongsToMany(Suburb::class, 'service_zone_suburb')
            ->using(ServiceZoneSuburb::class)
            ->withTimestamps();
    }

    /**
     * Get the stock locations whose inventory backs this zone's
     * availability.
     *
     * @return BelongsToMany<StockLocation, $this, ServiceZoneStockLocation>
     */
    public function stockLocations(): BelongsToMany
    {
        return $this->belongsToMany(StockLocation::class, 'service_zone_stock_location')
            ->using(ServiceZoneStockLocation::class)
            ->withTimestamps();
    }

    /**
     * ISR tag for every storefront surface built from the coverage tree
     * (home coverage chips, footer, mega menu, city pages).
     *
     * @param  'created'|'updated'|'deleted'  $event
     * @return list<string>
     */
    public function revalidationTags(string $event): array
    {
        return ['locations'];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ServiceZoneType::class,
            'origin_lat' => 'decimal:7',
            'origin_lng' => 'decimal:7',
            'radius_km' => 'decimal:2',
            'operating_hours' => 'array',
            'priority' => 'integer',
            'status' => Status::class,
        ];
    }
}
