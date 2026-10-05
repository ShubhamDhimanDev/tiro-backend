<?php

namespace App\Models;

use Database\Factories\OrderLineItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A commerce line within an {@see Order} — the financial record only, not a
 * second copy of fitment/position detail (that lives on `BookingLineItem`
 * against the same booking this order is attached to). See
 * docs/architecture/01-data-model.md's `OrderLineItem` section.
 *
 * @property int $id
 * @property int $order_id
 * @property int $tyre_variant_id
 * @property int $quantity
 * @property int $unit_price
 * @property int $discount_amount
 * @property int $tax_amount
 * @property int $line_total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['order_id', 'tyre_variant_id', 'quantity', 'unit_price', 'discount_amount', 'tax_amount', 'line_total'])]
class OrderLineItem extends Model
{
    /** @use HasFactory<OrderLineItemFactory> */
    use HasFactory;

    /**
     * Get the order this line item belongs to.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
            'unit_price' => 'integer',
            'discount_amount' => 'integer',
            'tax_amount' => 'integer',
            'line_total' => 'integer',
        ];
    }
}
