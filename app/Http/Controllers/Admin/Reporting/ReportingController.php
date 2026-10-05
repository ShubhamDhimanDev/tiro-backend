<?php

namespace App\Http\Controllers\Admin\Reporting;

use App\Http\Controllers\Controller;
use App\Models\ServiceZone;
use App\Services\Reporting\ReportingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `GET /admin/reporting/{dashboard}` — one Inertia route/controller action
 * for all 5 dashboards (`sales|bookings|conversion|cancellation|product-
 * performance`), per the Phase 6 task brief. Gated `permission:reporting.view`
 * at the route level (`routes/admin.php`) — verified against
 * `RolesAndPermissionsSeeder`: super_admin/ecommerce/operations/fleet hold
 * it, customer_support does not (no `reporting` entry in its module-tier
 * row).
 *
 * All aggregation lives in {@see ReportingService} (backend-agent's,
 * live-query only) — this controller only resolves the shared
 * date-range/zone-filter input and dispatches to the right method, never
 * reimplements any aggregation itself.
 */
class ReportingController extends Controller
{
    /**
     * Every value `{dashboard}` may take — an unrecognised value 404s
     * (a route-parameter mismatch, not a user-correctable form error).
     *
     * @var list<string>
     */
    private const DASHBOARDS = ['sales', 'bookings', 'conversion', 'cancellation', 'product-performance'];

    private const DEFAULT_RANGE_DAYS = 29;

    private const DEFAULT_PRODUCT_LIMIT = 10;

    public function __construct(private readonly ReportingService $reporting) {}

    public function show(Request $request, string $dashboard): Response
    {
        abort_unless(in_array($dashboard, self::DASHBOARDS, true), 404);

        [$from, $to] = $this->resolveRange($request);
        $serviceZoneId = $request->integer('service_zone_id') ?: null;
        $limit = min(max($request->integer('limit', self::DEFAULT_PRODUCT_LIMIT), 1), 50);

        $data = match ($dashboard) {
            'sales' => $this->reporting->sales($from, $to, $serviceZoneId),
            'bookings' => $this->reporting->bookings($from, $to, $serviceZoneId),
            'conversion' => $this->reporting->conversion($from, $to, $serviceZoneId),
            'cancellation' => $this->reporting->cancellation($from, $to, $serviceZoneId),
            'product-performance' => $this->reporting->productPerformance($from, $to, $serviceZoneId, $limit)->values(),
        };

        return Inertia::render('reporting/show', [
            'dashboard' => $dashboard,
            'data' => $data,
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'service_zone_id' => $serviceZoneId,
                'limit' => $limit,
            ],
            'serviceZones' => ServiceZone::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Resolve `[from, to]` from `?from=&to=` query params (both `Y-m-d`),
     * defaulting to the trailing 30 days (today inclusive) when absent or
     * unparseable — never a validation error on a GET filter, same posture
     * as `OrderController::validDateFilter()`.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveRange(Request $request): array
    {
        $to = $this->parseDate($request->string('to')->toString()) ?? CarbonImmutable::now();
        $from = $this->parseDate($request->string('from')->toString()) ?? $to->subDays(self::DEFAULT_RANGE_DAYS);

        return [$from->startOfDay(), $to->endOfDay()];
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        if ($value === '' || ! strtotime($value)) {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
