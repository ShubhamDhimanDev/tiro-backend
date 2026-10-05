<?php

namespace App\Models;

use App\Enums\Status;
use App\Enums\TechnicianEmploymentType;
use Database\Factories\TechnicianFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A fitting technician — the capacity unit the booking engine schedules
 * against via {@see TechnicianShift}. `user_id` stays nullable by design;
 * see docs/architecture/07-admin-auth-permissions.md §5.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property TechnicianEmploymentType $employment_type
 * @property array<int, string>|null $certifications
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'name', 'employment_type', 'certifications', 'status'])]
class Technician extends Model
{
    /** @use HasFactory<TechnicianFactory> */
    use HasFactory;

    /**
     * Get the admin-panel login this technician uses, if one has been
     * provisioned.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get this technician's roster shifts.
     *
     * @return HasMany<TechnicianShift, $this>
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(TechnicianShift::class);
    }

    /**
     * Get the bookings assigned to this technician.
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
            'employment_type' => TechnicianEmploymentType::class,
            'certifications' => 'array',
            'status' => Status::class,
        ];
    }
}
