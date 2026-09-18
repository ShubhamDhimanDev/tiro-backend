<?php

namespace App\Models;

use Database\Factories\SuburbFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * `lat`/`lng` are required (not nullable) — radius-type zone resolution
 * computes distance from `ServiceZone.origin_lat/lng` to a suburb's
 * centroid, so a null-coordinate suburb would silently and permanently
 * fail radius matching.
 *
 * @property int $id
 * @property string $name
 * @property int $state_id
 * @property string $postcode
 * @property string $lat
 * @property string $lng
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'state_id', 'postcode', 'lat', 'lng'])]
class Suburb extends Model
{
    /** @use HasFactory<SuburbFactory> */
    use HasFactory;

    /**
     * Get the state this suburb belongs to.
     *
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * Get the suburb-list-type service zones this suburb is assigned to.
     *
     * @return BelongsToMany<ServiceZone, $this, ServiceZoneSuburb>
     */
    public function serviceZones(): BelongsToMany
    {
        return $this->belongsToMany(ServiceZone::class, 'service_zone_suburb')
            ->using(ServiceZoneSuburb::class)
            ->withTimestamps();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
        ];
    }
}
