<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/v1/customer/orders` list item shape — see docs/architecture
 * (Phase 7 readiness pass). Summary only; the detail view reuses the
 * existing `GET /api/v1/orders/{order}` unmodified.
 *
 * @mixin Order
 */
class CustomerOrderSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $booking = $this->booking;

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'payment_status' => $this->payment_status->value,
            'grand_total' => $this->grand_total,
            'currency' => $this->currency,
            'placed_at' => $this->placed_at?->toIso8601String(),
            'booking' => $booking === null ? null : [
                'scheduled_date' => $booking->scheduled_date->toDateString(),
                'slot_start' => substr($booking->slot_start, 0, 5),
                'slot_end' => substr($booking->slot_end, 0, 5),
            ],
        ];
    }
}
