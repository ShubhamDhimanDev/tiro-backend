<?php

namespace App\Models;

use App\Enums\PriceGuaranteeClaimStatus;
use Database\Factories\PriceGuaranteeClaimFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer-submitted "beat this competitor price" claim — see
 * docs/architecture/05-promotions-pricing.md's "Price-guarantee claim
 * workflow" section. Deliberately human-reviewed, never auto-approved.
 * Submission is `auth:customer`-only, no guest path.
 *
 * @property int $id
 * @property int $customer_id
 * @property int|null $order_id
 * @property string $competitor_url
 * @property int $competitor_price
 * @property int $tyre_variant_id
 * @property PriceGuaranteeClaimStatus $status
 * @property int|null $approved_discount_amount
 * @property Carbon|null $expires_at
 * @property Carbon|null $redeemed_at
 * @property string|null $admin_note
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'customer_id', 'order_id', 'competitor_url', 'competitor_price', 'tyre_variant_id', 'status',
    'approved_discount_amount', 'expires_at', 'redeemed_at', 'admin_note', 'resolved_by', 'resolved_at',
])]
class PriceGuaranteeClaim extends Model
{
    /** @use HasFactory<PriceGuaranteeClaimFactory> */
    use HasFactory;

    /**
     * Scope to a customer's approved, not-yet-redeemed, still-valid claims —
     * the exact `POST /api/v1/cart/calculate`/`POST /api/v1/orders`
     * "does a usable pre-purchase claim exist for this customer+variant"
     * lookup.
     *
     * @param  Builder<PriceGuaranteeClaim>  $query
     * @return Builder<PriceGuaranteeClaim>
     */
    public function scopeUsable(Builder $query, int $customerId, int $tyreVariantId): Builder
    {
        return $query
            ->where('customer_id', $customerId)
            ->where('tyre_variant_id', $tyreVariantId)
            ->where('status', PriceGuaranteeClaimStatus::Approved)
            ->whereNull('redeemed_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Get the customer who submitted this claim.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the order this claim was matched against, if it's a post-purchase
     * claim.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the tyre variant this claim is about.
     *
     * @return BelongsTo<TyreVariant, $this>
     */
    public function tyreVariant(): BelongsTo
    {
        return $this->belongsTo(TyreVariant::class);
    }

    /**
     * Get the admin who approved/rejected this claim.
     *
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'competitor_price' => 'integer',
            'status' => PriceGuaranteeClaimStatus::class,
            'approved_discount_amount' => 'integer',
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
