<?php

namespace App\Models;

use App\Contracts\RevalidatesFrontend;
use App\Enums\Status;
use Database\Factories\StateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An AU state/territory. `is_active` is the admin toggle that turns
 * "Melbourne-only -> multi-state" into a config change, not a migration —
 * seed all 8 states/territories upfront and flip more on as geography
 * expands (see docs/architecture/06-open-decisions.md item 4).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'is_active', 'status'])]
class State extends Model implements RevalidatesFrontend
{
    /** @use HasFactory<StateFactory> */
    use HasFactory;

    /**
     * Only states the admin has switched on (`is_active`) and published
     * (`status`). The storefront's served area is built from these alone, so
     * flipping a state off in the admin panel removes it from coverage.
     *
     * @param  Builder<State>  $query
     * @return Builder<State>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('status', Status::Active);
    }

    /**
     * Get the service zones within this state.
     *
     * @return HasMany<ServiceZone, $this>
     */
    public function serviceZones(): HasMany
    {
        return $this->hasMany(ServiceZone::class);
    }

    /**
     * Get the suburbs within this state.
     *
     * @return HasMany<Suburb, $this>
     */
    public function suburbs(): HasMany
    {
        return $this->hasMany(Suburb::class);
    }

    /**
     * ISR tag for every storefront surface built from the coverage tree
     * (home coverage chips, footer, mega menu, city pages). A new state is
     * created inactive, so nothing public changes until it is updated.
     *
     * @param  'created'|'updated'|'deleted'  $event
     * @return list<string>
     */
    public function revalidationTags(string $event): array
    {
        return $event === 'created' ? [] : ['locations'];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'status' => Status::class,
        ];
    }
}
