<?php

namespace App\Http\Controllers\Admin\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Orders\RefundOrderRequest;
use App\Models\Order;
use App\Services\Commerce\RefundService;
use App\Support\Auth\AdminGuard;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * `POST /admin/orders/{order}/refund` — the underlying mechanism only; this
 * is a deliberately minimal controller-action wrapper around
 * {@see RefundService} so super-admin-agent's Orders admin screen/refund
 * dialog (not built yet) has a clean, already-working endpoint to call in a
 * later round — see docs/architecture/02-api-contract.md's "Orders admin —
 * refund flow" section. Gated on `permission:orders.refund` at the route
 * level (`routes/admin.php`), never `orders.manage` — see
 * docs/architecture/07-admin-auth-permissions.md §3.2.
 *
 * Guarded by the `idempotency` middleware (same format-validation-only
 * convention as `POST /api/v1/bookings`/`POST /api/v1/orders` — see
 * `App\Http\Middleware\Idempotency`), so a real double-click/back-button/
 * network-lag double-submit carries the same `Idempotency-Key` header on
 * both requests. This controller just forwards that header into
 * `RefundService::refund()`, which does the actual dedup lookup — see that
 * method's docblock.
 *
 * A `ValidationException` from `RefundService::refund()` (amount exceeds
 * the remaining refundable balance, or nothing left to refund) is
 * deliberately left uncaught here — Laravel's default exception handling
 * already redirects back with the validation error for a non-JSON
 * (Inertia) request, the same as any `FormRequest` failure.
 */
class OrderRefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    public function store(RefundOrderRequest $request, Order $order): RedirectResponse
    {
        $actor = AdminGuard::user($request);

        $amount = $request->validated('amount');
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        $this->refunds->refund($order, $actor, $amount === null ? null : (int) $amount, $request->validated('reason'), $idempotencyKey);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Refund processed.')]);

        return back();
    }
}
