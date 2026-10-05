<?php

namespace App\Http\Controllers\Admin\Promotions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Promotions\PromotionRequest;
use App\Models\Brand;
use App\Models\Promotion;
use App\Models\ServiceZone;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use App\Observers\PromotionObserver;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Campaign creation/edit/list for {@see Promotion} — `type`, `value`,
 * dates, usage/stock limits, `stackable`. Eligibility rows are a separate
 * nested resource, see {@see PromotionEligibilityController}. Gated on
 * `promotions.manage` at the route level (`routes/admin.php`) — see
 * docs/architecture/07-admin-auth-permissions.md §3.2 (Ecommerce or Super
 * Admin, not Customer Support/Operations).
 *
 * `AuditLog` writes for every create/update/delete are handled
 * automatically by {@see PromotionObserver} (registered in
 * `AppServiceProvider::boot()`) — do not add a second explicit
 * `AuditLog::create()` call here, it would double-log.
 */
class PromotionController extends Controller
{
    /**
     * Display every promotion (with its eligibility rows), plus the
     * catalogue/zone lookups the campaign form and eligibility editor need.
     */
    public function index(): Response
    {
        $promotions = Promotion::query()
            ->withCount('redemptions')
            ->with(['eligibilities.serviceZone:id,name'])
            ->orderByDesc('starts_at')
            ->orderBy('name')
            ->get();

        $brands = Brand::query()->orderBy('name')->get(['id', 'name']);

        $tyreModels = TyreModel::query()
            ->with('brand:id,name')
            ->orderBy('name')
            ->get(['id', 'brand_id', 'name']);

        $tyreVariants = TyreVariant::query()
            ->with(['tyreModel:id,brand_id,name', 'tyreModel.brand:id,name'])
            ->orderBy('sku')
            ->get(['id', 'tyre_model_id', 'sku', 'width', 'profile', 'rim_diameter']);

        $serviceZones = ServiceZone::query()->orderBy('name')->get(['id', 'name']);

        return Inertia::render('promotions/campaigns/index', [
            'promotions' => $promotions,
            'brands' => $brands,
            'tyreModels' => $tyreModels,
            'tyreVariants' => $tyreVariants,
            'serviceZones' => $serviceZones,
        ]);
    }

    /**
     * Store a newly created promotion.
     */
    public function store(PromotionRequest $request): RedirectResponse
    {
        Promotion::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Promotion created.')]);

        return back();
    }

    /**
     * Update the given promotion.
     */
    public function update(PromotionRequest $request, Promotion $promotion): RedirectResponse
    {
        $promotion->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Promotion updated.')]);

        return back();
    }

    /**
     * Delete the given promotion. `promotion_redemptions.promotion_id` is
     * `restrictOnDelete()` at the DB level (a campaign with real redemption/
     * order history must never be silently destroyed) — checked here first
     * so that shows as a clean flash message instead of a raw 500 from an
     * uncaught `QueryException`.
     */
    public function destroy(Promotion $promotion): RedirectResponse
    {
        if ($promotion->redemptions()->exists()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('This promotion has redemption history and cannot be deleted — set its status to archived instead.'),
            ]);

            return back();
        }

        $promotion->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Promotion deleted.')]);

        return back();
    }
}
