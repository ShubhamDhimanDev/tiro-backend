<?php

namespace App\Http\Controllers\Admin\Promotions;

use App\Http\Requests\Admin\Promotions\PromotionEligibilityRequest;
use App\Models\Promotion;
use App\Models\PromotionEligibility;
use App\Support\Auth\AdminGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Manages {@see PromotionEligibility} rows (scope + optional zone) nested
 * under a {@see Promotion} — add/remove only, no update-in-place (delete
 * the row and add a corrected one, same posture as
 * `ServiceZoneSuburbController`'s attach/detach-only pivot management).
 *
 * Route-level `permission:promotions.manage` middleware already gates this
 * (`routes/admin.php`); `destroy()`'s explicit `AdminGuard` check mirrors
 * `ServiceZoneSuburbController`'s same defense-in-depth pattern for an
 * action with no `FormRequest` of its own to carry an `authorize()` check.
 */
class PromotionEligibilityController
{
    /**
     * Add an eligibility rule to the given promotion.
     */
    public function store(PromotionEligibilityRequest $request, Promotion $promotion): RedirectResponse
    {
        $promotion->eligibilities()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Eligibility rule added.')]);

        return back();
    }

    /**
     * Remove an eligibility rule from the given promotion.
     */
    public function destroy(Request $request, Promotion $promotion, PromotionEligibility $promotionEligibility): RedirectResponse
    {
        abort_unless(AdminGuard::optionalUser($request)?->can('promotions.manage'), 403);
        abort_unless($promotionEligibility->promotion_id === $promotion->id, 404);

        $promotionEligibility->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Eligibility rule removed.')]);

        return back();
    }
}
