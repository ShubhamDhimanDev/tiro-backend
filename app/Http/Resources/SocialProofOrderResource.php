<?php

namespace App\Http\Resources;

use App\Http\Controllers\Api\V1\SocialProof\RecentOrderController;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One anonymised "recently purchased" row. Wraps a plain array built by
 * {@see RecentOrderController}, never
 * an Order model, so no PII-bearing attribute can leak by accident — the
 * explicit key list below is the whole public surface.
 *
 * @property array{first_name: string, suburb: string, state: string, product_label: string, purchased_at: string} $resource
 */
class SocialProofOrderResource extends JsonResource
{
    /**
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        return [
            'first_name' => $this->resource['first_name'],
            'suburb' => $this->resource['suburb'],
            'state' => $this->resource['state'],
            'product_label' => $this->resource['product_label'],
            'purchased_at' => $this->resource['purchased_at'],
        ];
    }
}
