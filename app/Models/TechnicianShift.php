<?php

namespace App\Models;

use App\Enums\Status;
use Database\Factories\TechnicianShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The capacity source of truth the booking engine reads/writes — see
 * docs/architecture/04-booking-capacity-engine.md. `shift_end > shift_start`
 * is enforced both here (nothing app-level currently mutates rows outside
 * factories/admin CRUD, which validate on the way in) and as a MySQL 8
 * `CHECK` constraint in the migration.
 *
 * @property int $id
 * @property int $technician_id
 * @property int $van_id
 * @property int $service_zone_id
 * @property Carbon $date
 * @property string $shift_start
 * @property string $shift_end
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['technician_id', 'van_id', 'service_zone_id', 'date', 'shift_start', 'shift_end', 'status'])]
class TechnicianShift extends Model
{
    /** @use HasFactory<TechnicianShiftFactory> */
    use HasFactory;

    /**
     * Get the technician rostered for this shift.
     *
     * @return BelongsTo<Technician, $this>
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /**
     * Get the van assigned to this shift.
     *
     * @return BelongsTo<Van, $this>
     */
    public function van(): BelongsTo
    {
        return $this->belongsTo(Van::class);
    }

    /**
     * Get the service zone this shift covers.
     *
     * @return BelongsTo<ServiceZone, $this>
     */
    public function serviceZone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => Status::class,
        ];
    }
}
