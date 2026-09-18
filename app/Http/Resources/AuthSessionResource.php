<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * The token-issuing response shape shared by register/verify, login,
 * otp/verify, and password/reset/verify — see
 * docs/architecture/08-customer-auth-otp.md §12.
 *
 * @property array{token: string, expires_at: Carbon, customer: Customer} $resource
 */
class AuthSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->resource['token'],
            'token_type' => 'Bearer',
            'expires_at' => $this->resource['expires_at']->toIso8601String(),
            'customer' => new CustomerResource($this->resource['customer']),
        ];
    }
}
