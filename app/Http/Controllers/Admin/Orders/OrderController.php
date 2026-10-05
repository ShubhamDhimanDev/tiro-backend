<?php

namespace App\Http\Controllers\Admin\Orders;

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Orders\UpdateOrderStatusRequest;
use App\Models\AuditLog;
use App\Models\Order;
use App\Services\Bookings\BookingCancellationService;
use App\Support\Auth\AdminGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Orders admin — search/view/cancel/status-update. Refund lives separately
 * in {@see OrderRefundController} (built
 * ahead of this screen, gated on the standalone `orders.refund` permission
 * — see that class's docblock). Every read action here is gated on
 * `orders.view` at the route level (`routes/admin.php`); `orders.manage`
 * implies `orders.view` for every role that holds it
 * (`RolesAndPermissionsSeeder::permissionNamesForTier()`), so this
 * controller never needs to distinguish the two tiers itself — the route
 * middleware group a given action sits in *is* the authorization boundary.
 *
 * See docs/architecture/01-data-model.md's `Order`/`Payment` sections for
 * the `status` vs `payment_status` split and the `refund_required` edge
 * case this module must surface, not hide.
 */
class OrderController extends Controller
{
    /**
     * `Order.status` values an admin may cancel from. Deliberately excludes
     * `refund_required`: that status means Stripe already captured payment
     * for a booking slot that's no longer held, so the correct remediation
     * is a refund (via `OrderRefundController`, which itself transitions
     * `status` once the money is actually returned) — cancelling it away
     * here instead would leave the customer charged with no order and no
     * audit trail of a refund ever happening. Also excludes every terminal
     * status (`completed`, `cancelled`, `refunded`, `partially_refunded`).
     *
     * @var list<OrderStatus>
     */
    private const CANCELLABLE_STATUSES = [
        OrderStatus::PendingPayment,
        OrderStatus::Confirmed,
        OrderStatus::PaymentFailed,
    ];

    /**
     * Manual `status` transitions an admin may apply via the status-update
     * control, keyed by current status. Deliberately narrow: every
     * payment-derived status (`pending_payment`, `payment_failed`,
     * `refund_required`, `refunded`, `partially_refunded`) is only ever set
     * by the Stripe webhook handler or `RefundService`, never by hand, so
     * `status` can't drift out of sync with `payment_status`/the `Payment`
     * ledger. The one safe manual transition is marking a confirmed order
     * `completed` (the fitting job is done) — `updateStatus()` also
     * transitions the linked `Booking` to `BookingStatus::Completed` in the
     * same transaction when this happens (Phase 7 fix: previously nothing in
     * this codebase transitioned a booking into that status at all, which
     * also meant `App\Observers\BookingNotificationObserver`'s `Completed`
     * branch was unreachable). This is currently the only way an order ever
     * reaches `completed`. Cancellation is deliberately NOT reachable through
     * this map — it has
     * its own dedicated action/endpoint below so there is exactly one code
     * path that cancels an order, not two that could diverge.
     *
     * @var array<string, list<OrderStatus>>
     */
    private const MANUAL_STATUS_TRANSITIONS = [
        'confirmed' => [OrderStatus::Completed],
    ];

    public function __construct(private readonly BookingCancellationService $bookingCancellation) {}

    /**
     * Search/filter the order list. `status`/`payment_status` filters are
     * validated against their backed enums at the query boundary
     * (`::tryFrom()`, never a raw string comparison) — an unrecognised
     * value is simply dropped rather than thrown, since this is a
     * read-only GET filter a bad/stale query string shouldn't 500 or
     * validation-error a page load. `status=refund_required` must work
     * here like any other status filter — see this controller's class
     * docblock and docs/architecture/01-data-model.md's `Order.status`
     * "refund_required" note: ops must be able to find these, not just see
     * one on its own detail page.
     */
    public function index(Request $request): Response
    {
        $filters = [
            'status' => $this->validEnumFilter($request, 'status', OrderStatus::class),
            'payment_status' => $this->validEnumFilter($request, 'payment_status', PaymentStatus::class),
            'date_from' => $this->validDateFilter($request, 'date_from'),
            'date_to' => $this->validDateFilter($request, 'date_to'),
            'search' => $request->string('search')->trim()->toString() ?: null,
        ];

        $orders = Order::query()
            ->with(['customer:id,name,email'])
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->when($filters['payment_status'], fn ($query, $status) => $query->where('payment_status', $status))
            ->when($filters['date_from'], fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'], fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when($filters['search'], function ($query, $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('order_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($query) use ($search): void {
                            $query->where('email', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('orders/index', [
            'filters' => $filters,
            'orders' => $orders,
        ]);
    }

    /**
     * Order detail: line items, fitting address, the linked booking's
     * appointment summary, and payment/refund history. `payments` is
     * scoped to an explicit column list that excludes `raw_response` (the
     * full raw Stripe API response) and `idempotency_key` — security-agent
     * flagged `raw_response` as something that must never reach an
     * admin-visible view, not just be hidden client-side; scoping the
     * query itself means it's never fetched/hydrated in the first place,
     * not merely omitted from a manually-built response array that a
     * future edit could accidentally widen back to `$payment->toArray()`.
     */
    public function show(Order $order): Response
    {
        $order->load([
            'customer:id,name,email,mobile',
            'address.suburb:id,name,state_id,postcode',
            'address.suburb.state:id,code,name',
            'booking:id,order_id,service_zone_id,scheduled_date,slot_start,slot_end,technician_id,van_id,status,duration_minutes,addons,access_notes',
            'booking.serviceZone:id,name',
            'booking.technician:id,name',
            'booking.van:id,name,rego',
            'lineItems:id,order_id,tyre_variant_id,quantity,unit_price,discount_amount,tax_amount,line_total',
            'lineItems.tyreVariant:id,tyre_model_id,sku,width,profile,rim_diameter,load_index,speed_rating',
            'lineItems.tyreVariant.tyreModel:id,brand_id,name',
            'lineItems.tyreVariant.tyreModel.brand:id,name',
            'payments' => fn ($query) => $query
                ->select(['id', 'order_id', 'type', 'gateway', 'method', 'status', 'amount', 'gateway_reference', 'created_at'])
                ->orderBy('created_at'),
        ]);

        return Inertia::render('orders/show', [
            'order' => $order,
            'cancellable' => in_array($order->status, self::CANCELLABLE_STATUSES, true),
            'manualStatusOptions' => array_map(
                fn (OrderStatus $status): string => $status->value,
                self::MANUAL_STATUS_TRANSITIONS[$order->status->value] ?? [],
            ),
        ]);
    }

    /**
     * Cancel an order. Per docs/architecture/01-data-model.md's
     * `Order.booking_id` note (required + unique — every order originates
     * from exactly one already-resolved booking, this project has no
     * delivery-only/no-fitting path), cancelling an order must also free
     * its linked booking's technician/van slot rather than leave a
     * cancelled order pointing at a booking still occupying the schedule.
     * Reuses {@see BookingCancellationService} (the exact mechanism behind
     * `DispatchBoardController::cancel()`) rather than duplicating that
     * write — see that service's docblock. The booking side is a best
     * -effort courtesy release: if the linked booking is already in a
     * terminal state (completed/cancelled/no_show/expired) there's nothing
     * to free and this silently skips that step rather than failing the
     * order cancellation over it.
     *
     * Deliberately does not touch `payment_status` — a cancelled order can
     * legitimately sit at `payment_status = paid` pending a separate
     * refund decision (via `OrderRefundController`, gated on
     * `orders.refund`); collapsing "cancel" and "refund" into one action
     * would let a Customer Support agent (who holds `orders.manage` but
     * not `orders.refund`) trigger money movement they're not authorized
     * for.
     */
    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $actor = AdminGuard::user($request);

        // RBAC hardening pass fix, 2026-09-23 (Phase 6): this method has no
        // FormRequest to carry an `authorize()` defense-in-depth check
        // (plain `Request`, not a dedicated FormRequest class), and
        // previously had no explicit permission check of its own either —
        // relying entirely on route middleware (`permission:orders.manage`)
        // with zero defense-in-depth, unlike every other mutation in this
        // codebase. Mirrors `DispatchBoardController::cancel()`'s identical
        // `abort_unless($actor?->can(...), 403)` guard.
        abort_unless($actor->can('orders.manage'), 403);

        if (! in_array($order->status, self::CANCELLABLE_STATUSES, true)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This order can no longer be cancelled.')]);

            return back();
        }

        DB::transaction(function () use ($order, $actor): void {
            $previousStatus = $order->status;

            $order->forceFill(['status' => OrderStatus::Cancelled])->save();

            AuditLog::create([
                'auditable_type' => Order::class,
                'auditable_id' => $order->id,
                'action' => 'orders.cancelled',
                'actor_id' => $actor->id,
                'before' => ['status' => $previousStatus->value],
                'after' => ['status' => OrderStatus::Cancelled->value],
            ]);

            $booking = $order->booking;

            if ($booking !== null && in_array($booking->status, BookingCancellationService::CANCELLABLE_STATUSES, true)) {
                $this->bookingCancellation->cancel($booking, $actor);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Order cancelled.')]);

        return back();
    }

    /**
     * Apply one of the narrow, allow-listed manual status transitions — see
     * {@see MANUAL_STATUS_TRANSITIONS}. Any other requested transition
     * (including anything landing on a payment-derived status, or
     * `cancelled`) is rejected as a validation error rather than silently
     * ignored, matching this project's standing "unhandled config value
     * fails loudly" convention.
     */
    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): RedirectResponse
    {
        $actor = AdminGuard::user($request);
        $target = OrderStatus::from($request->validated('status'));

        $allowedTargets = self::MANUAL_STATUS_TRANSITIONS[$order->status->value] ?? [];

        if (! in_array($target, $allowedTargets, true)) {
            throw ValidationException::withMessages([
                'status' => [__('Order cannot be moved from :from to :to.', ['from' => $order->status->value, 'to' => $target->value])],
            ]);
        }

        $previousStatus = $order->status;

        DB::transaction(function () use ($order, $target, $actor, $previousStatus): void {
            $order->forceFill(['status' => $target])->save();

            AuditLog::create([
                'auditable_type' => Order::class,
                'auditable_id' => $order->id,
                'action' => 'orders.status_updated',
                'actor_id' => $actor->id,
                'before' => ['status' => $previousStatus->value],
                'after' => ['status' => $target->value],
            ]);

            // Phase 7 gap fix — an order reaching `completed` must also
            // complete its linked booking; this is what makes
            // `App\Observers\BookingNotificationObserver`'s `Completed`
            // branch (and `App\Notifications\BookingCompleted`) reachable at
            // all. Same transaction as the order's own status write.
            // Unconditional rather than gated on `$target === OrderStatus::Completed`
            // — `$target` is provably always `Completed` here (the only
            // value {@see MANUAL_STATUS_TRANSITIONS} currently allows
            // through this method), which phpstan flags as an always-true
            // comparison either as an `if` or a `match`. If that map ever
            // grows a second manually-reachable target, this line needs a
            // matching conditional added back.
            $order->booking?->forceFill(['status' => BookingStatus::Completed])->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Order status updated.')]);

        return back();
    }

    /**
     * Resolve a `$key` query-string filter to a valid backed-enum value, or
     * null if absent/unrecognised. `::tryFrom()` at the import boundary,
     * not a raw string comparison — same convention as this project's other
     * small-closed-vocabulary boundaries (see
     * docs/architecture/01-data-model.md's Vehicles fragile-pattern note).
     *
     * @param  class-string<OrderStatus|PaymentStatus>  $enumClass
     */
    private function validEnumFilter(Request $request, string $key, string $enumClass): ?string
    {
        $value = $request->string($key)->trim()->toString();

        if ($value === '') {
            return null;
        }

        return $enumClass::tryFrom($value)?->value;
    }

    private function validDateFilter(Request $request, string $key): ?string
    {
        $value = $request->string($key)->trim()->toString();

        if ($value === '' || ! strtotime($value)) {
            return null;
        }

        return $value;
    }
}
