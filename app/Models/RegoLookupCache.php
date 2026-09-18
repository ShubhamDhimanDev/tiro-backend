<?php

namespace App\Models;

use Database\Factories\RegoLookupCacheFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Caches a rego-lookup vendor response to cap cost on metered lookup APIs —
 * see docs/architecture/01-data-model.md. Schema-only this phase: no
 * read/write code path exists until a vendor is contracted (open decision
 * #3). This model exists only so future phases' foreign keys resolve
 * against a real table/relationship.
 *
 * @property int $id
 * @property string $rego
 * @property string $state
 * @property array<string, mixed> $raw_response
 * @property int|null $resolved_vehicle_id
 * @property Carbon $looked_up_at
 * @property string|null $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['rego', 'state', 'raw_response', 'resolved_vehicle_id', 'looked_up_at', 'status'])]
class RegoLookupCache extends Model
{
    /** @use HasFactory<RegoLookupCacheFactory> */
    use HasFactory;

    /**
     * Get the vehicle this lookup resolved to, if any.
     *
     * @return BelongsTo<Vehicle, $this>
     */
    public function resolvedVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'resolved_vehicle_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_response' => 'array',
            'looked_up_at' => 'datetime',
        ];
    }
}
