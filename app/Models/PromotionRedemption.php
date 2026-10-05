<?php

namespace App\Models;

use App\Contracts\HasHold;
use App\Enums\PromotionRedemptionStatus;
use Database\Factories\PromotionRedemptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Doubles as the promo-stock hold, not just the post-purchase redemption
 * record — see docs/architecture/05-promotions-pricing.md's "Promo
 * stock-limit enforcement" section. Every promotion applied to a booking
 * (stock-limited or not) gets a row here in `held` status at
 * `POST /api/v1/bookings` time; only stock-limited promotions gate that
 * insert behind a `Cache::lock()` re-check.
 *
 * Implements {@see HasHold} for interface/type consistency but is
 * deliberately **not** registered in `config('holds.models')` —
 * `hold_expires_at` is always structurally equal to the parent
 * `Booking.hold_expires_at`, so `Booking::releaseHold()` (and every explicit
 * cancel path) cascades to release its own held children instead of a
 * second independent sweep.
 *
 * @implements HasHold<PromotionRedemption>
 *
 * @property int $id
 * @property int $promotion_id
 * @property int $booking_id
 * @property int|null $order_id
 * @property int|null $customer_id
 * @property int $quantity
 * @property int|null $discount_amount
 * @property PromotionRedemptionStatus $status
 * @property Carbon|null $hold_expires_at
 * @property Carbon|null $redeemed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['promotion_id', 'booking_id', 'order_id', 'customer_id', 'quantity', 'discount_amount', 'status', 'hold_expires_at', 'redeemed_at'])]
class PromotionRedemption extends Model implements HasHold
{
    /** @use HasFactory<PromotionRedemptionFactory> */
    use HasFactory;

    /**
     * Release this redemption's hold: mark it `released` and clear
     * `hold_expires_at`. A no-op for a redemption that isn't currently
     * `held` (already confirmed/released) — same idempotent-no-op shape as
     * {@see Booking::releaseHold()}.
     */
    public function releaseHold(): void
    {
        if ($this->status !== PromotionRedemptionStatus::Held) {
            return;
        }

        $this->forceFill([
            'status' => PromotionRedemptionStatus::Released,
            'hold_expires_at' => null,
        ])->save();
    }

    /**
     * Scope to rows whose hold has expired and are still `held` — mirrors
     * {@see Booking::scopeExpiredHolds()}'s shape exactly, even though
     * nothing sweeps this scope independently (see this class's docblock).
     *
     * @param  Builder<PromotionRedemption>  $query
     * @return Builder<PromotionRedemption>
     */
    public function scopeExpiredHolds(Builder $query): Builder
    {
        return $query
            ->where('status', PromotionRedemptionStatus::Held)
            ->where('hold_expires_at', '<', now());
    }

    /**
     * Scope to rows currently `held` — the cascade-release query's shape
     * ({@see Booking::releasePromotionHolds()}) and the stock-limit
     * re-check's `IN ('held', 'confirmed')` half.
     *
     * @param  Builder<PromotionRedemption>  $query
     * @return Builder<PromotionRedemption>
     */
    public function scopeHeld(Builder $query): Builder
    {
        return $query->where('status', PromotionRedemptionStatus::Held);
    }

    /**
     * Get the promotion this redemption/hold is against.
     *
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /**
     * Get the booking this hold was created for.
     *
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the order this redemption was confirmed against, once payment
     * succeeds — null while still `held`.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the customer this redemption belongs to, if known at hold time
     * (null for a guest booking not yet linked to an account).
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
            'discount_amount' => 'integer',
            'status' => PromotionRedemptionStatus::class,
            'hold_expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
        ];
    }
}
