<?php

namespace App\Models;

use App\Enums\VehicleFitmentPosition;
use Database\Factories\BookingLineItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A cart line within a {@see Booking} — see
 * docs/architecture/01-data-model.md's `BookingLineItem` section.
 * `position` reuses {@see VehicleFitmentPosition}, not a second near-identical
 * enum.
 *
 * @property int $id
 * @property int $booking_id
 * @property int $tyre_variant_id
 * @property int $quantity
 * @property VehicleFitmentPosition $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['booking_id', 'tyre_variant_id', 'quantity', 'position'])]
class BookingLineItem extends Model
{
    /** @use HasFactory<BookingLineItemFactory> */
    use HasFactory;

    /**
     * Get the booking this line item belongs to.
     *
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the tyre SKU this line item is for.
     *
     * @return BelongsTo<TyreVariant, $this>
     */
    public function tyreVariant(): BelongsTo
    {
        return $this->belongsTo(TyreVariant::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'position' => VehicleFitmentPosition::class,
        ];
    }
}
