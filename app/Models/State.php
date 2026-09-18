<?php

namespace App\Models;

use App\Enums\Status;
use Database\Factories\StateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
class State extends Model
{
    /** @use HasFactory<StateFactory> */
    use HasFactory;

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
