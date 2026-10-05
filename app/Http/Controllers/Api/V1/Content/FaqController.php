<?php

namespace App\Http\Controllers\Api\V1\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Content\FaqIndexRequest;
use App\Http\Resources\FaqResource;
use App\Models\Faq;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public read-only `Faq` endpoint — see the Phase 6 task brief's "Public
 * read API" section. Not paginated: a small, bounded set, same posture as
 * `GET /api/v1/vehicles/makes`.
 */
class FaqController extends Controller
{
    public function index(FaqIndexRequest $request): AnonymousResourceCollection
    {
        $query = Faq::query()->published()->orderBy('sort_order');

        if ($category = $request->validated('category')) {
            $query->where('category', $category);
        } elseif (! $request->filled('content_page_id')) {
            // Omitting both filters returns every published *global* FAQ
            // (content_page_id IS NULL) across all categories — see the
            // Phase 6 task brief. A `category` filter alone intentionally
            // does NOT also constrain to global-only, since a category can
            // legitimately span both global and page-scoped FAQs.
            $query->whereNull('content_page_id');
        }

        if ($request->filled('content_page_id')) {
            $query->where('content_page_id', $request->integer('content_page_id'));
        }

        return FaqResource::collection($query->get());
    }
}
