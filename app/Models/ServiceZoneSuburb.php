<?php

namespace App\Models;

use Database\Factories\ServiceZoneSuburbFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Pivot: which suburbs a `suburb_list`-type {@see ServiceZone} covers.
 *
 * @property int $id
 * @property int $service_zone_id
 * @property int $suburb_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['service_zone_id', 'suburb_id'])]
class ServiceZoneSuburb extends Pivot
{
    /** @use HasFactory<ServiceZoneSuburbFactory> */
    use HasFactory;

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = true;

    /**
     * Get the service zone side of this pivot.
     *
     * @return BelongsTo<ServiceZone, $this>
     */
    public function serviceZone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class);
    }

    /**
     * Get the suburb side of this pivot.
     *
     * @return BelongsTo<Suburb, $this>
     */
    public function suburb(): BelongsTo
    {
        return $this->belongsTo(Suburb::class);
    }
}
