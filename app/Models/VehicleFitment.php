<?php

namespace App\Models;

use App\Enums\Status;
use App\Enums\VehicleFitmentConfidence;
use App\Enums\VehicleFitmentPosition;
use App\Enums\VehicleFitmentSource;
use App\Services\Vehicles\FitmentSetValidator;
use Database\Factories\VehicleFitmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * OE tyre size mapping for a {@see Vehicle} position — see
 * docs/architecture/01-data-model.md's "Vehicles & fitment" section for the
 * `is_staggered`/`position` agreement rules, enforced at the write layer by
 * {@see FitmentSetValidator}, not by this model.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property VehicleFitmentPosition $position
 * @property int $width
 * @property int $profile
 * @property int $rim_diameter
 * @property string|null $load_index
 * @property string|null $speed_rating
 * @property bool $is_staggered
 * @property VehicleFitmentSource $source
 * @property VehicleFitmentConfidence $confidence
 * @property string|null $notes
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'vehicle_id', 'position', 'width', 'profile', 'rim_diameter', 'load_index', 'speed_rating',
    'is_staggered', 'source', 'confidence', 'notes', 'status',
])]
class VehicleFitment extends Model
{
    /** @use HasFactory<VehicleFitmentFactory> */
    use HasFactory;

    /**
     * Get the vehicle this fitment row belongs to.
     *
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => VehicleFitmentPosition::class,
            'width' => 'integer',
            'profile' => 'integer',
            'rim_diameter' => 'integer',
            'is_staggered' => 'boolean',
            'source' => VehicleFitmentSource::class,
            'confidence' => VehicleFitmentConfidence::class,
            'status' => Status::class,
        ];
    }
}
