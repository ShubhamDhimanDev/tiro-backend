<?php

namespace App\Http\Controllers\Api\V1\Content;

use App\Enums\ContentPageType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Content\ContentPageIndexRequest;
use App\Http\Resources\ContentPageDetailResource;
use App\Http\Resources\ContentPageSummaryResource;
use App\Models\ContentPage;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public read-only `ContentPage` endpoints — see the Phase 6 task brief's
 * "Public read API" section. Every row returned, listing or single-item,
 * passes {@see ContentPage::scopePublished()}'s visibility gate; there is
 * no caller (including an authenticated admin) that bypasses it here — an
 * admin previews a draft through the Inertia admin panel instead, not this
 * API.
 */
class ContentPageController extends Controller
{
    private const PER_PAGE = 15;

    /**
     * Short/legacy URLs for the legal pages (`/pages/terms`, `/pages/privacy`)
     * resolve to their canonical seeded slug rather than 404ing.
     *
     * @var array<string, string>
     */
    private const PAGE_SLUG_ALIASES = [
        'terms' => 'terms-conditions',
        'terms-and-conditions' => 'terms-conditions',
        'privacy' => 'privacy-policy',
    ];

    public function index(ContentPageIndexRequest $request): AnonymousResourceCollection
    {
        $type = ContentPageType::from($request->validated('type'));

        $query = ContentPage::query()
            ->published()
            ->where('type', $type)
            ->orderBy('sort_order')
            ->orderByDesc('published_at');

        if ($category = $request->validated('category')) {
            $query->where('category', $category);
        }

        $perPage = min($request->integer('per_page', self::PER_PAGE), 100);

        return ContentPageSummaryResource::collection($query->paginate($perPage)->withQueryString());
    }

    /**
     * `404` (not `403`) when the slug doesn't exist for `$type`, OR exists
     * but isn't currently publicly visible (unpublished/future-scheduled/
     * archived) — both cases are indistinguishable to a public caller by
     * design.
     */
    public function show(string $type, string $slug): ContentPageDetailResource
    {
        $typeEnum = ContentPageType::tryFrom($type);

        abort_if($typeEnum === null, 404);

        $page = ContentPage::query()
            ->published()
            ->where('type', $typeEnum)
            ->where('slug', $typeEnum === ContentPageType::Page ? (self::PAGE_SLUG_ALIASES[$slug] ?? $slug) : $slug)
            ->with(['serviceZone', 'promotion'])
            ->first();

        abort_if($page === null, 404);

        return new ContentPageDetailResource($page);
    }
}
