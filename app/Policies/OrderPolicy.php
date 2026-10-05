<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Row-level authorization for {@see Order} — mirrors {@see BookingPolicy}'s
 * `manage()` shape exactly, applied to `view` instead (there's no
 * customer-facing order mutation this phase, only
 * `GET /api/v1/orders/{order}`). Guests are authorized separately by the
 * controller via `X-Order-Token` (hashed comparison against
 * `Order.guest_token_hash`) — a guest has no `Authenticatable` to hand this
 * policy at all, same posture as `BookingPolicy`'s guest path.
 */
class OrderPolicy
{
    /**
     * Authenticated customer ownership check backing `GET /api/v1/orders/{order}`.
     */
    public function view(Authenticatable $user, Order $order): bool
    {
        return $user instanceof Customer
            && $order->customer_id !== null
            && $order->customer_id === $user->id;
    }
}
