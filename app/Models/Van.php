<?php

namespace App\Models;

use App\Enums\Status;
use Database\Factories\VanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A service vehicle — the daily job-cap unit for the booking engine, see
 * docs/architecture/04-booking-capacity-engine.md's "Slot computation" step
 * 4.
 *
 * @property int $id
 * @property string $rego
 * @property string $name
 * @property int $home_stock_location_id
 * @property bool $has_alignment_equipment
 * @property int $max_jobs_per_day
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['rego', 'name', 'home_stock_location_id', 'has_alignment_equipment', 'max_jobs_per_day', 'status'])]
class Van extends Model
{
    /** @use HasFactory<VanFactory> */
    use HasFactory;

    /**
     * Get the van's home stock location.
     *
     * @return BelongsTo<StockLocation, $this>
     */
    public function homeStockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'home_stock_location_id');
    }

    /**
     * Get the shifts this van has been assigned to.
     *
     * @return HasMany<TechnicianShift, $this>
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(TechnicianShift::class);
    }

    /**
     * Get the bookings assigned to this van.
     *
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'has_alignment_equipment' => 'boolean',
            'max_jobs_per_day' => 'integer',
            'status' => Status::class,
        ];
    }
}
