<?php

namespace App\Models;

use App\Enums\PaymentGateway;
use App\Enums\PaymentMethod;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single charge or refund transaction against an {@see Order} — one
 * `Order` can have multiple `Payment` rows (the original charge, plus one
 * row per refund; partial refunds are each individually auditable, never
 * collapsed into a mutated single row). See
 * docs/architecture/01-data-model.md's `Payment` section.
 *
 * @property int $id
 * @property int $order_id
 * @property PaymentType $type
 * @property PaymentGateway $gateway
 * @property PaymentMethod $method
 * @property PaymentTransactionStatus $status
 * @property int $amount
 * @property string $gateway_reference
 * @property string|null $gateway_capture_reference
 * @property array<string, mixed>|null $raw_response
 * @property string|null $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['order_id', 'type', 'gateway', 'method', 'status', 'amount', 'gateway_reference', 'gateway_capture_reference', 'raw_response', 'idempotency_key'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * Get the order this payment/refund belongs to.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PaymentType::class,
            'gateway' => PaymentGateway::class,
            'method' => PaymentMethod::class,
            'status' => PaymentTransactionStatus::class,
            'amount' => 'integer',
            'raw_response' => 'array',
        ];
    }
}
