<?php

namespace App\Http\Controllers\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Content\ContentPageRequest;
use App\Models\ContentPage;
use App\Models\Promotion;
use App\Models\ServiceZone;
use App\Observers\ContentPageObserver;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin CRUD for {@see ContentPage} — all 5 `ContentPageType` values share
 * this one screen/controller (type-conditional fields on the React side),
 * per the Phase 6 task brief. Gated on `content.view`/`content.manage` at
 * the route level (`routes/admin.php`) — verified against
 * `RolesAndPermissionsSeeder`: super_admin and ecommerce hold `manage`,
 * every other role holds `none` for the `content` module.
 *
 * `AuditLog` writes for every create/update/delete are handled automatically
 * by {@see ContentPageObserver} (registered in `AppServiceProvider::boot()`)
 * — do not add a second explicit `AuditLog::create()` call here, it would
 * double-log. ISR revalidation is likewise automatic via
 * `App\Observers\FrontendRevalidationObserver` on the same model.
 */
class ContentPageController extends Controller
{
    /**
     * Display every content page (with its own scoped FAQs, for the edit
     * dialog's nested FAQ editor — same "save the parent first, then attach
     * children" pattern as `ServiceZoneController`/`PromotionController`),
     * plus the service-zone/promotion lookups the type-conditional fields
     * need.
     */
    public function index(): Response
    {
        $contentPages = ContentPage::query()
            ->with([
                'serviceZone:id,name',
                'promotion:id,name',
                'faqs' => fn ($query) => $query->orderBy('sort_order'),
            ])
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->get();

        $serviceZones = ServiceZone::query()->orderBy('name')->get(['id', 'name']);
        $promotions = Promotion::query()->orderBy('name')->get(['id', 'name']);

        return Inertia::render('content/pages/index', [
            'contentPages' => $contentPages,
            'serviceZones' => $serviceZones,
            'promotions' => $promotions,
        ]);
    }

    /**
     * Store a newly created content page.
     */
    public function store(ContentPageRequest $request): RedirectResponse
    {
        ContentPage::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Content page created.')]);

        return back();
    }

    /**
     * Update the given content page.
     */
    public function update(ContentPageRequest $request, ContentPage $contentPage): RedirectResponse
    {
        $contentPage->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Content page updated.')]);

        return back();
    }

    /**
     * Delete the given content page. `faqs.content_page_id` cascades on
     * delete at the DB level (see that migration) — a page's own scoped
     * FAQs are deleted along with it, not orphaned or blocked.
     */
    public function destroy(ContentPage $contentPage): RedirectResponse
    {
        $contentPage->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Content page deleted.')]);

        return back();
    }
}
