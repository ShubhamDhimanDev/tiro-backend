<?php

namespace App\Http\Resources;

use App\Models\PriceGuaranteeClaim;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PriceGuaranteeClaim
 */
class PriceGuaranteeClaimResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'competitor_url' => $this->competitor_url,
            'competitor_price' => $this->competitor_price,
            'tyre_variant_id' => $this->tyre_variant_id,
            'order_id' => $this->order_id,
            'approved_discount_amount' => $this->approved_discount_amount,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'redeemed_at' => $this->redeemed_at?->toIso8601String(),
            'admin_note' => $this->admin_note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
