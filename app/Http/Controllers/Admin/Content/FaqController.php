<?php

namespace App\Http\Controllers\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Content\FaqRequest;
use App\Models\ContentPage;
use App\Models\Faq;
use App\Observers\FaqObserver;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin CRUD for {@see Faq} — both global (`content_page_id = null`) and
 * page-scoped rows share this one controller/request. One standalone
 * management screen lists every FAQ (global and page-scoped alike, with a
 * "scope" column); `ContentPageController`'s edit dialog also posts to
 * these same `store`/`update`/`destroy` actions for a page's own scoped
 * FAQs, pre-filling `content_page_id` — no separate nested-resource
 * controller, per the Phase 6 task brief's "reasonable default" UX.
 *
 * `AuditLog` writes are automatic via {@see FaqObserver} — do not add a
 * second explicit `AuditLog::create()` call here.
 */
class FaqController extends Controller
{
    /**
     * Display every FAQ, global and page-scoped, plus the content-page
     * lookup the scope dropdown needs.
     */
    public function index(): Response
    {
        $faqs = Faq::query()
            ->with('contentPage:id,title,type')
            ->orderByRaw('content_page_id is null desc')
            ->orderBy('content_page_id')
            ->orderBy('sort_order')
            ->get();

        $contentPages = ContentPage::query()
            ->orderBy('title')
            ->get(['id', 'title', 'type']);

        return Inertia::render('content/faqs/index', [
            'faqs' => $faqs,
            'contentPages' => $contentPages,
        ]);
    }

    /**
     * Store a newly created FAQ.
     */
    public function store(FaqRequest $request): RedirectResponse
    {
        Faq::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('FAQ created.')]);

        return back();
    }

    /**
     * Update the given FAQ.
     */
    public function update(FaqRequest $request, Faq $faq): RedirectResponse
    {
        $faq->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('FAQ updated.')]);

        return back();
    }

    /**
     * Delete the given FAQ.
     */
    public function destroy(Faq $faq): RedirectResponse
    {
        $faq->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('FAQ deleted.')]);

        return back();
    }
}
