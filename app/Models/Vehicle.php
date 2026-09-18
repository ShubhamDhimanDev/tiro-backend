<?php

namespace App\Models;

use App\Enums\Status;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A make/model/generation entry the manual vehicle picker resolves to — see
 * docs/architecture/01-data-model.md's "Vehicles & fitment" section.
 *
 * @property int $id
 * @property string $make
 * @property string $model
 * @property string|null $series
 * @property string|null $body_type
 * @property int $year_from
 * @property int $year_to
 * @property string $slug
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['make', 'model', 'series', 'body_type', 'year_from', 'year_to', 'slug', 'status'])]
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory;

    /**
     * Auto-generate `slug` at creation time when it isn't already set — same
     * convention as {@see TyreVariant}. Once created, `slug` is
     * admin-editable and is never regenerated on update. `slug` is reserved/
     * unrouted this phase (see the data model doc) — no route depends on it
     * yet, but it must still be unique and stable once assigned.
     */
    protected static function booted(): void
    {
        static::creating(function (self $vehicle): void {
            if (blank($vehicle->slug)) {
                $vehicle->slug = static::generateUniqueSlug($vehicle);
            }
        });
    }

    /**
     * Generate the `{make}-{model}-{series?}-{year_from}-{year_to}` slug for
     * a not-yet-persisted vehicle. `series`/`body_type` don't uniquely
     * distinguish two vehicles sharing every other field (e.g. a sedan and a
     * hatch of the same generation) since `body_type` isn't part of the
     * slug format, so collisions are expected in practice, not just a
     * theoretical edge case — falls back to a numeric suffix (`-2`, `-3`,
     * ...) on collision.
     */
    public static function generateUniqueSlug(self $vehicle): string
    {
        $parts = collect([$vehicle->make, $vehicle->model, $vehicle->series, $vehicle->year_from, $vehicle->year_to])
            ->filter(fn ($part) => filled($part));

        $base = Str::slug($parts->implode('-'));

        if (! static::slugTaken($base)) {
            return $base;
        }

        $suffix = 2;

        do {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        } while (static::slugTaken($candidate));

        return $candidate;
    }

    /**
     * Determine whether a slug is already in use by another vehicle.
     */
    protected static function slugTaken(string $slug): bool
    {
        return static::query()->where('slug', $slug)->exists();
    }

    /**
     * Get the OE fitment row(s) for this vehicle.
     *
     * @return HasMany<VehicleFitment, $this>
     */
    public function fitments(): HasMany
    {
        return $this->hasMany(VehicleFitment::class);
    }

    /**
     * Get the customer-saved vehicles resolved to this catalogue entry.
     *
     * @return HasMany<CustomerVehicle, $this>
     */
    public function customerVehicles(): HasMany
    {
        return $this->hasMany(CustomerVehicle::class);
    }

    /**
     * Get the rego-lookup cache rows that resolved to this vehicle.
     *
     * @return HasMany<RegoLookupCache, $this>
     */
    public function regoLookupCaches(): HasMany
    {
        return $this->hasMany(RegoLookupCache::class, 'resolved_vehicle_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year_from' => 'integer',
            'year_to' => 'integer',
            'status' => Status::class,
        ];
    }
}
