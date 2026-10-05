<?php

namespace App\Http\Controllers\Admin\Promotions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Promotions\PriceRuleRequest;
use App\Models\PriceRule;
use App\Models\ServiceZone;
use App\Observers\PriceRuleObserver;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD for {@see PriceRule} — a per-zone **service-fee** adjustment, not a
 * per-zone product repricing matrix, per
 * docs/architecture/06-open-decisions.md item 7. Gated on
 * `promotions.manage` at the route level (`routes/admin.php`), not
 * `locations.manage` — see
 * docs/architecture/05-promotions-pricing.md's RBAC decision. `AuditLog`
 * writes for every create/update/delete are handled automatically by
 * {@see PriceRuleObserver} (registered in
 * `AppServiceProvider::boot()`) — do not add a second explicit
 * `AuditLog::create()` call here, it would double-log.
 */
class PriceRuleController extends Controller
{
    /**
     * Display every price rule, plus the zone lookup the form needs.
     */
    public function index(): Response
    {
        $priceRules = PriceRule::query()
            ->with('serviceZone:id,name')
            ->orderBy('service_zone_id')
            ->get();

        $serviceZones = ServiceZone::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('promotions/price-rules/index', [
            'priceRules' => $priceRules,
            'serviceZones' => $serviceZones,
        ]);
    }

    /**
     * Store a newly created price rule.
     */
    public function store(PriceRuleRequest $request): RedirectResponse
    {
        PriceRule::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Price rule created.')]);

        return back();
    }

    /**
     * Update the given price rule.
     */
    public function update(PriceRuleRequest $request, PriceRule $priceRule): RedirectResponse
    {
        $priceRule->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Price rule updated.')]);

        return back();
    }

    /**
     * Delete the given price rule.
     */
    public function destroy(PriceRule $priceRule): RedirectResponse
    {
        $priceRule->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Price rule deleted.')]);

        return back();
    }
}
