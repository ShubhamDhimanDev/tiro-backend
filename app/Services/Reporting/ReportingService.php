<?php

namespace App\Services\Reporting;

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Http\Controllers\Admin\Bookings\DispatchBoardController;
use App\Http\Controllers\Admin\Orders\OrderController;
use App\Models\Booking;
use App\Models\Order;
use App\Models\OrderLineItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Live-query aggregates behind `GET /admin/reporting/{dashboard}` (Inertia
 * route/controller/UI is super-admin-agent's, per the Phase 6 task brief —
 * this class is what its controller calls per dashboard). No new
 * aggregation/materialized-summary tables — every method queries
 * `Order`/`OrderLineItem`/`Booking`/`Payment` directly, consistent with this
 * project's no-CI/single-VPS/no-pre-optimization posture elsewhere.
 *
 * Every method takes at minimum a `[$from, $to]` date range and an optional
 * `$serviceZoneId` filter. Two different date axes are used deliberately,
 * not one uniform choice:
 *
 * - {@see bookings()} and {@see cancellation()} filter on
 *   `Booking.scheduled_date` — an operational/capacity view ("what's
 *   happening on the schedule in this window"), matching how the rest of
 *   this codebase already filters bookings (e.g. the dispatch board).
 * - {@see conversion()} filters on `Booking.created_at` — a cohort view
 *   ("of the holds *created* in this window, what fraction converted"),
 *   which only makes sense anchored to hold-creation time, not appointment
 *   time.
 * - {@see sales()} and {@see productPerformance()} filter on
 *   `Order.placed_at` — "revenue recognized in this window".
 *
 * No client-supplied numbers anywhere — every figure here is computed from
 * the query itself, same "never trust client input for computed figures"
 * posture as cart/order pricing.
 */
class ReportingService
{
    /**
     * `Order.status` values that represent a genuinely placed/paid order for
     * revenue-counting purposes — excludes `pending_payment` (payment never
     * completed) and `payment_failed` (never will) and `cancelled` (the
     * order was voided). `refund_required` is deliberately included: money
     * was captured, even though it's earmarked to be given back — see
     * `OrderStatus`'s own docblock on that status.
     *
     * @var list<OrderStatus>
     */
    private const REVENUE_STATUSES = [
        OrderStatus::Confirmed,
        OrderStatus::RefundRequired,
        OrderStatus::Completed,
        OrderStatus::PartiallyRefunded,
        OrderStatus::Refunded,
    ];

    /**
     * Revenue totals, order counts/status breakdown, and average order
     * value for orders placed within `[$from, $to]`.
     *
     * @return array{
     *     order_count: int,
     *     paid_order_count: int,
     *     gross_revenue: int,
     *     refunds_total: int,
     *     net_revenue: int,
     *     average_order_value: float,
     *     orders_by_status: array<string, int>,
     * }
     */
    public function sales(CarbonInterface $from, CarbonInterface $to, ?int $serviceZoneId = null): array
    {
        $base = $this->ordersInRange($from, $to, $serviceZoneId);

        $orderCount = (clone $base)->count();

        // `orders.` prefix is required here, not cosmetic: when
        // `$serviceZoneId` is set, `$base` already carries a `bookings`
        // join (see `ordersInRange()`), and `bookings` also has its own
        // `status` column — a bare `status` here would be an ambiguous
        // column reference at the SQL level.
        $paidOrders = (clone $base)->whereIn('orders.status', self::REVENUE_STATUSES);
        $paidOrderCount = (clone $paidOrders)->count();
        $grossRevenue = (int) (clone $paidOrders)->sum('grand_total');

        $refundsTotal = (int) (clone $paidOrders)
            ->join('payments', 'payments.order_id', '=', 'orders.id')
            ->where('payments.type', PaymentType::Refund)
            ->where('payments.status', PaymentTransactionStatus::Succeeded)
            ->sum('payments.amount');

        // `pluck('total', 'status')` on an Eloquent builder only casts the
        // *value* column through the model's casts, never the key column
        // (see `Illuminate\Database\Eloquent\Builder::pluck()`) — so the
        // key here is always the raw DB string (e.g. "confirmed"), never a
        // hydrated `OrderStatus` instance.
        $ordersByStatus = (clone $base)
            ->selectRaw('orders.status, COUNT(*) as total')
            ->groupBy('orders.status')
            ->pluck('total', 'status')
            ->all();

        return [
            'order_count' => $orderCount,
            'paid_order_count' => $paidOrderCount,
            'gross_revenue' => $grossRevenue,
            'refunds_total' => $refundsTotal,
            'net_revenue' => $grossRevenue - $refundsTotal,
            'average_order_value' => $paidOrderCount > 0 ? round($grossRevenue / $paidOrderCount, 2) : 0.0,
            'orders_by_status' => $ordersByStatus,
        ];
    }

    /**
     * Booking counts and status breakdown for bookings scheduled within
     * `[$from, $to]`.
     *
     * @return array{booking_count: int, bookings_by_status: array<string, int>}
     */
    public function bookings(CarbonInterface $from, CarbonInterface $to, ?int $serviceZoneId = null): array
    {
        $base = $this->bookingsByScheduledDate($from, $to, $serviceZoneId);

        // See sales()'s identical `pluck('total', 'status')` comment — the
        // key here is always the raw DB string too.
        $bookingsByStatus = (clone $base)
            ->selectRaw('bookings.status, COUNT(*) as total')
            ->groupBy('bookings.status')
            ->pluck('total', 'status')
            ->all();

        return [
            'booking_count' => (clone $base)->count(),
            'bookings_by_status' => $bookingsByStatus,
        ];
    }

    /**
     * "Hold→order conversion rate" — the fraction of holds *created* within
     * `[$from, $to]` that reach a paid order, vs. expire or get cancelled.
     * Deliberately NOT true site-visit-to-booking funnel conversion — this
     * project has no session/pageview tracking to compute that from; see
     * the Phase 6 task brief. "Reaches a paid order" is verified against
     * the `payments` table directly (at least one succeeded `charge` row on
     * the linked order), not the order's current denormalized
     * `payment_status` — a booking whose order was later fully refunded
     * still genuinely converted at the time, and this stays accurate for
     * that case.
     *
     * @return array{
     *     total_holds: int,
     *     converted_count: int,
     *     expired_count: int,
     *     cancelled_count: int,
     *     conversion_rate: float,
     * }
     */
    public function conversion(CarbonInterface $from, CarbonInterface $to, ?int $serviceZoneId = null): array
    {
        $base = Booking::query()
            ->whereBetween('bookings.created_at', [$from, $to])
            ->when($serviceZoneId, fn (Builder $query) => $query->where('bookings.service_zone_id', $serviceZoneId));

        $total = (clone $base)->count();

        $converted = (clone $base)
            ->whereNotNull('bookings.order_id')
            ->whereHas('order.payments', fn (Builder $query) => $query
                ->where('type', PaymentType::Charge)
                ->where('status', PaymentTransactionStatus::Succeeded))
            ->count();

        $expired = (clone $base)->where('bookings.status', BookingStatus::Expired)->count();
        $cancelled = (clone $base)->where('bookings.status', BookingStatus::Cancelled)->count();

        return [
            'total_holds' => $total,
            'converted_count' => $converted,
            'expired_count' => $expired,
            'cancelled_count' => $cancelled,
            'conversion_rate' => $total > 0 ? round($converted / $total, 4) : 0.0,
        ];
    }

    /**
     * Staff- vs. customer-initiated cancellation split, for bookings
     * scheduled within `[$from, $to]`. Both land on the identical
     * `Booking.status = cancelled`, with no stored `initiated_by` column —
     * the distinguishing signal is the presence/absence of a matching
     * `AuditLog` row (`action = 'bookings.cancelled'`), written by
     * `App\Services\Bookings\BookingCancellationService::cancel()` on every
     * staff-initiated path
     * ({@see DispatchBoardController::cancel()},
     * {@see OrderController::cancel()}'s
     * cascade) and never written by the customer-facing
     * `Api\V1\Bookings\BookingController::cancel()` endpoint — verified
     * against the actual shipped code, not assumed (see the Phase 6 task
     * brief). `expired` is already its own bucket, no `AuditLog` lookup
     * needed.
     *
     * @return array{
     *     staff_initiated: int,
     *     customer_initiated: int,
     *     expired: int,
     *     total_cancelled: int,
     * }
     */
    public function cancellation(CarbonInterface $from, CarbonInterface $to, ?int $serviceZoneId = null): array
    {
        $base = $this->bookingsByScheduledDate($from, $to, $serviceZoneId);

        $expired = (clone $base)->where('bookings.status', BookingStatus::Expired)->count();

        // `.toBase()` drops down to a plain query-builder/stdClass result
        // rather than an Eloquent-hydrated `Booking` — these two aggregate
        // columns aren't real `Booking` attributes, and stdClass sidesteps
        // any "undefined property" static-analysis noise a partial model
        // hydration would otherwise invite.
        $split = (clone $base)
            ->where('bookings.status', BookingStatus::Cancelled)
            ->leftJoin('audit_logs', function ($join) {
                $join->on('audit_logs.auditable_id', '=', 'bookings.id')
                    ->where('audit_logs.auditable_type', '=', Booking::class)
                    ->where('audit_logs.action', '=', 'bookings.cancelled');
            })
            ->selectRaw('COUNT(DISTINCT CASE WHEN audit_logs.id IS NOT NULL THEN bookings.id END) as staff_initiated')
            ->selectRaw('COUNT(DISTINCT CASE WHEN audit_logs.id IS NULL THEN bookings.id END) as customer_initiated')
            ->toBase()
            ->first();

        $staffInitiated = (int) ($split->staff_initiated ?? 0);
        $customerInitiated = (int) ($split->customer_initiated ?? 0);

        return [
            'staff_initiated' => $staffInitiated,
            'customer_initiated' => $customerInitiated,
            'expired' => $expired,
            'total_cancelled' => $staffInitiated + $customerInitiated,
        ];
    }

    /**
     * Top-selling `TyreVariant`s by units/revenue, for order line items on
     * orders placed within `[$from, $to]`. Excludes line items on orders
     * that never resulted in revenue (see {@see REVENUE_STATUSES}).
     *
     * `.toBase()` (plain query-builder `stdClass` rows, not Eloquent
     * `OrderLineItem` hydration) for the same reason as
     * {@see cancellation()}'s `$split` query — these aggregate columns
     * aren't real `OrderLineItem` attributes. Each row has
     * `tyre_variant_id: int, sku: string, tyre_model_id: int,
     * tyre_model_name: string, units_sold: int, revenue: int`.
     *
     * @return Collection<int, \stdClass>
     */
    public function productPerformance(CarbonInterface $from, CarbonInterface $to, ?int $serviceZoneId = null, int $limit = 10): Collection
    {
        return OrderLineItem::query()
            ->join('orders', 'orders.id', '=', 'order_line_items.order_id')
            ->join('tyre_variants', 'tyre_variants.id', '=', 'order_line_items.tyre_variant_id')
            ->join('tyre_models', 'tyre_models.id', '=', 'tyre_variants.tyre_model_id')
            ->when(
                $serviceZoneId,
                fn (Builder $query) => $query
                    ->join('bookings', 'bookings.id', '=', 'orders.booking_id')
                    ->where('bookings.service_zone_id', $serviceZoneId),
            )
            ->whereBetween('orders.placed_at', [$from, $to])
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->groupBy('tyre_variants.id', 'tyre_variants.sku', 'tyre_models.id', 'tyre_models.name')
            ->selectRaw('tyre_variants.id as tyre_variant_id, tyre_variants.sku, tyre_models.id as tyre_model_id, tyre_models.name as tyre_model_name')
            ->selectRaw('SUM(order_line_items.quantity) as units_sold')
            ->selectRaw('SUM(order_line_items.line_total) as revenue')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->toBase()
            ->get();
    }

    /**
     * @return Builder<Order>
     */
    private function ordersInRange(CarbonInterface $from, CarbonInterface $to, ?int $serviceZoneId): Builder
    {
        // Deliberately no `->select('orders.*')` on the joined branch: every
        // caller of this method only ever runs `count()`/`sum()`/its own
        // explicit `selectRaw()` against the result, never a plain `get()`,
        // and Eloquent's aggregate methods already reset the select clause
        // internally. Adding an explicit `orders.*` select here would
        // instead *stack* underneath a caller's own `selectRaw(...)
        // ->groupBy(...)` (Eloquent's `selectRaw()` appends, it doesn't
        // replace), producing a `SELECT orders.*, orders.status, COUNT(*)
        // ... GROUP BY orders.status` that fails under MySQL's
        // `ONLY_FULL_GROUP_BY` — found via a real test failure, not
        // theoretical.
        return Order::query()
            ->whereBetween('orders.placed_at', [$from, $to])
            ->when(
                $serviceZoneId,
                fn (Builder $query) => $query
                    ->join('bookings', 'bookings.id', '=', 'orders.booking_id')
                    ->where('bookings.service_zone_id', $serviceZoneId),
            );
    }

    /**
     * @return Builder<Booking>
     */
    private function bookingsByScheduledDate(CarbonInterface $from, CarbonInterface $to, ?int $serviceZoneId): Builder
    {
        return Booking::query()
            ->whereBetween('bookings.scheduled_date', [$from->toDateString(), $to->toDateString()])
            ->when($serviceZoneId, fn (Builder $query) => $query->where('bookings.service_zone_id', $serviceZoneId));
    }
}
