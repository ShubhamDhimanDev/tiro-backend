<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PriceGuaranteeClaimStatus;
use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\PriceGuaranteeClaim;
use App\Models\Promotion;
use App\Models\Review;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use App\Services\Reporting\ReportingService;
use App\Support\Auth\AdminGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `GET /admin/dashboard` — the landing page after login. Every block is gated
 * on the same permission the matching admin module uses, so a role only ever
 * sees figures it could already open elsewhere; a block the user can't see is
 * sent as `null` rather than computed. Heavy aggregation reuses
 * {@see ReportingService}; the rest are cheap count/limit queries.
 */
class DashboardController extends Controller
{
    private const WINDOW_DAYS = 30;

    private const SERIES_DAYS = 14;

    public function __construct(private readonly ReportingService $reporting) {}

    public function __invoke(Request $request): Response
    {
        $user = AdminGuard::user($request);

        $can = fn (string $module): bool => $user->hasAnyPermission(["{$module}.view", "{$module}.manage"]);

        $now = Carbon::now();
        $from = $now->copy()->subDays(self::WINDOW_DAYS - 1)->startOfDay();

        return Inertia::render('dashboard', [
            'windowDays' => self::WINDOW_DAYS,
            'sales' => $user->hasAnyPermission(['reporting.view']) || $can('orders')
                ? $this->sales($from, $now)
                : null,
            'orders' => $can('orders') ? $this->orders() : null,
            'bookings' => $user->hasAnyPermission(['bookings.manage']) ? $this->bookings() : null,
            'customers' => $can('customers') ? $this->customers() : null,
            'inventory' => $can('inventory') ? $this->inventory() : null,
            'promotions' => $can('promotions') ? $this->promotions() : null,
            'catalogue' => $can('products') ? $this->catalogue() : null,
            'reviews' => $can('content') ? $this->reviews() : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sales(Carbon $from, Carbon $to): array
    {
        $totals = $this->reporting->sales($from, $to);

        $daily = Order::query()
            ->whereBetween('placed_at', [$to->copy()->subDays(self::SERIES_DAYS - 1)->startOfDay(), $to])
            ->whereIn('status', ReportingService::REVENUE_STATUSES)
            ->selectRaw('DATE(placed_at) as day, COUNT(*) as orders, SUM(grand_total) as revenue')
            ->groupBy('day')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $series = collect(range(self::SERIES_DAYS - 1, 0))
            ->map(function (int $daysAgo) use ($to, $daily): array {
                $date = $to->copy()->subDays($daysAgo)->toDateString();

                return [
                    'date' => $date,
                    'orders' => (int) ($daily[$date]->orders ?? 0),
                    'revenue' => (int) ($daily[$date]->revenue ?? 0),
                ];
            })
            ->values()
            ->all();

        return [
            'net_revenue' => $totals['net_revenue'],
            'gross_revenue' => $totals['gross_revenue'],
            'refunds_total' => $totals['refunds_total'],
            'order_count' => $totals['order_count'],
            'paid_order_count' => $totals['paid_order_count'],
            'average_order_value' => $totals['average_order_value'],
            'orders_by_status' => $totals['orders_by_status'],
            'daily' => $series,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orders(): array
    {
        return [
            'awaiting_payment' => Order::query()->where('status', OrderStatus::PendingPayment)->count(),
            'needs_refund' => Order::query()->where('status', OrderStatus::RefundRequired)->count(),
            'payment_failed' => Order::query()->where('status', OrderStatus::PaymentFailed)->count(),
            'recent' => Order::query()
                ->with('customer:id,name')
                ->latest('placed_at')
                ->limit(8)
                ->get(['id', 'order_number', 'customer_id', 'status', 'grand_total', 'placed_at'])
                ->map(fn (Order $order): array => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer' => $order->customer?->name,
                    'status' => $order->status->value,
                    'grand_total' => $order->grand_total,
                    'placed_at' => $order->placed_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bookings(): array
    {
        $today = Carbon::today();

        $active = [BookingStatus::Confirmed, BookingStatus::InProgress];

        return [
            'today_count' => Booking::query()->whereDate('scheduled_date', $today)->whereIn('status', $active)->count(),
            'next_seven_days' => Booking::query()
                ->whereBetween('scheduled_date', [$today->copy()->addDay(), $today->copy()->addDays(7)])
                ->whereIn('status', $active)
                ->count(),
            'unassigned' => Booking::query()
                ->where('status', BookingStatus::Confirmed)
                ->whereDate('scheduled_date', '>=', $today)
                ->whereNull('technician_id')
                ->count(),
            'today' => Booking::query()
                ->with(['customer:id,name', 'technician:id,name', 'serviceZone:id,name'])
                ->whereDate('scheduled_date', $today)
                ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::InProgress, BookingStatus::Completed])
                ->orderBy('slot_start')
                ->limit(10)
                ->get()
                ->map(fn (Booking $booking): array => [
                    'id' => $booking->id,
                    'order_id' => $booking->order_id,
                    'slot_start' => $booking->slot_start,
                    'slot_end' => $booking->slot_end,
                    'status' => $booking->status->value,
                    'customer' => $booking->customer?->name,
                    'technician' => $booking->technician?->name,
                    'zone' => $booking->serviceZone?->name,
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function customers(): array
    {
        return [
            'total' => Customer::query()->count(),
            'new_last_seven_days' => Customer::query()->where('created_at', '>=', Carbon::now()->subDays(7))->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inventory(): array
    {
        $low = InventoryItem::query()->whereRaw('qty_on_hand - qty_reserved <= reorder_point');

        return [
            'low_stock_count' => (clone $low)->count(),
            'out_of_stock_count' => InventoryItem::query()->whereRaw('qty_on_hand - qty_reserved <= 0')->count(),
            'low_stock' => (clone $low)
                ->with(['tyreVariant:id,tyre_model_id,sku,width,profile,rim_diameter', 'tyreVariant.tyreModel:id,name', 'stockLocation:id,name'])
                ->orderByRaw('qty_on_hand - qty_reserved')
                ->limit(6)
                ->get()
                ->map(fn (InventoryItem $item): array => [
                    'id' => $item->id,
                    'location_id' => $item->stock_location_id,
                    'location' => $item->stockLocation?->name,
                    'model' => $item->tyreVariant?->tyreModel?->name,
                    'size' => $item->tyreVariant
                        ? "{$item->tyreVariant->width}/{$item->tyreVariant->profile}R{$item->tyreVariant->rim_diameter}"
                        : null,
                    'available' => $item->qty_on_hand - $item->qty_reserved,
                    'reorder_point' => $item->reorder_point,
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function promotions(): array
    {
        return [
            'active' => Promotion::query()
                ->where('status', Status::Active)
                ->whereDate('starts_at', '<=', Carbon::today())
                ->whereDate('ends_at', '>=', Carbon::today())
                ->count(),
            'pending_claims' => PriceGuaranteeClaim::query()->where('status', PriceGuaranteeClaimStatus::Pending)->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function catalogue(): array
    {
        return [
            'brands' => Brand::query()->where('status', Status::Active)->count(),
            'models' => TyreModel::query()->where('status', Status::Active)->count(),
            'variants' => TyreVariant::query()->where('status', Status::Active)->count(),
            'inactive_variants' => TyreVariant::query()->where('status', '!=', Status::Active)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviews(): array
    {
        $visible = Review::query()->where('is_hidden', false);

        return [
            'count' => (clone $visible)->count(),
            'average_rating' => round((float) (clone $visible)->avg('rating'), 1),
            'hidden_count' => Review::query()->where('is_hidden', true)->count(),
        ];
    }
}
