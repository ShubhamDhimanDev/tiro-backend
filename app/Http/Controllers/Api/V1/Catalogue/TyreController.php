<?php

namespace App\Http\Controllers\Api\V1\Catalogue;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Catalogue\TyreAvailabilityRequest;
use App\Http\Requests\Api\Catalogue\TyreFacetsRequest;
use App\Http\Requests\Api\Catalogue\TyreIndexRequest;
use App\Http\Requests\Api\Catalogue\TyreLatestReleasesRequest;
use App\Http\Requests\Api\Catalogue\TyrePriceLaddersRequest;
use App\Http\Resources\PopularSizeResource;
use App\Http\Resources\TyreVariantDetailResource;
use App\Http\Resources\TyreVariantResource;
use App\Models\PopularSize;
use App\Models\ServiceZone;
use App\Models\TyreVariant;
use App\Services\Catalogue\FourForThreeFlagService;
use App\Services\Catalogue\TyreFacetsBuilder;
use App\Services\Catalogue\TyrePriceLadderBuilder;
use App\Services\Catalogue\TyreSearchFilters;
use App\Services\Catalogue\ZoneStockCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * `TyreVariant`-level search/browse + PDP endpoints — see
 * docs/architecture/02-api-contract.md's "Catalogue & location endpoints"
 * section. Queries the database directly: structured filters are plain
 * indexed `WHERE`s, and the optional free-text `q` param is a simple `LIKE`
 * match (see {@see TyreSearchFilters}).
 */
class TyreController extends Controller
{
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly ZoneStockCalculator $stockCalculator,
        private readonly TyreSearchFilters $searchFilters,
        private readonly FourForThreeFlagService $fourForThreeFlags,
        private readonly TyreFacetsBuilder $facetsBuilder,
        private readonly TyrePriceLadderBuilder $ladderBuilder,
    ) {}

    public function index(TyreIndexRequest $request): JsonResponse
    {
        $zone = $this->resolveZone($request, 'zone');
        $perPage = $this->perPage($request);

        if ($request->boolean('staggered')) {
            $front = $this->paginatedResourceArray(
                $this->filteredQuery($request, [
                    'width' => $request->integer('front_width'),
                    'profile' => $request->integer('front_profile'),
                    'rim_diameter' => $request->integer('front_rim_diameter'),
                ]),
                $zone,
                $perPage,
                'front_page',
            );

            $rear = $this->paginatedResourceArray(
                $this->filteredQuery($request, [
                    'width' => $request->integer('rear_width'),
                    'profile' => $request->integer('rear_profile'),
                    'rim_diameter' => $request->integer('rear_rim_diameter'),
                ]),
                $zone,
                $perPage,
                'rear_page',
            );

            return response()->json(['data' => ['front' => $front, 'rear' => $rear]]);
        }

        $paginator = $this->filteredQuery($request, [
            'width' => $request->filled('width') ? $request->integer('width') : null,
            'profile' => $request->filled('profile') ? $request->integer('profile') : null,
            'rim_diameter' => $request->filled('rim_diameter') ? $request->integer('rim_diameter') : null,
        ])->paginate($perPage)->withQueryString();

        $this->attachListingFlags($paginator, $zone);
        $this->attachStockStatus($paginator, $zone);

        return TyreVariantResource::collection($paginator)->response();
    }

    public function latestReleases(TyreLatestReleasesRequest $request): AnonymousResourceCollection
    {
        $zone = $this->resolveZone($request, 'zone');

        $paginator = $this->baseQuery()
            ->orderByRaw('COALESCE(tyre_models.released_at, tyre_models.created_at) DESC')
            ->orderByDesc('tyre_variants.id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $this->attachListingFlags($paginator, $zone);
        $this->attachStockStatus($paginator, $zone);

        return TyreVariantResource::collection($paginator);
    }

    public function popularSizes(): AnonymousResourceCollection
    {
        $sizes = PopularSize::query()
            ->where('status', Status::Active)
            ->orderBy('sort_order')
            ->get();

        return PopularSizeResource::collection($sizes);
    }

    /**
     * Filter-sidebar option lists with counts, scoped by size/category/type.
     */
    public function facets(TyreFacetsRequest $request): JsonResponse
    {
        $query = $this->baseQuery();

        foreach (['width', 'profile', 'rim_diameter'] as $dimension) {
            if ($request->filled($dimension)) {
                $query->where("tyre_variants.{$dimension}", $request->integer($dimension));
            }
        }

        $this->searchFilters->apply($query, $request->safe()->only(['category', 'tyre_type']));

        return response()->json(['data' => $this->facetsBuilder->build($query)]);
    }

    /**
     * Per-tyre price for 1 to 5 tyres, from the real pricing engine.
     */
    public function priceLadders(TyrePriceLaddersRequest $request): JsonResponse
    {
        $zone = $this->resolveZone($request, 'zone');

        $variants = TyreVariant::query()
            ->whereIn('id', $request->variantIds())
            ->where('status', Status::Active)
            ->whereHas('tyreModel', fn (Builder $query) => $query->where('status', Status::Active)
                ->whereHas('brand', fn (Builder $brand) => $brand->where('status', Status::Active)))
            ->with('tyreModel.brand')
            ->get();

        $flags = $this->fourForThreeFlags->forVariants($variants, $zone?->id);

        return response()->json(['data' => $variants->mapWithKeys(fn (TyreVariant $variant): array => [
            (string) $variant->id => $this->ladderBuilder->build($variant, $zone?->id, $flags[$variant->id] ?? false),
        ])->all()]);
    }

    public function show(string $slug): TyreVariantDetailResource
    {
        // Eager-load constraint closures receive the underlying `Relation`
        // (e.g. `BelongsTo`), not an `Eloquent\Builder` — left untyped since
        // `Relation` proxies query-builder methods via `__call()`.
        $variant = TyreVariant::query()
            ->where('slug', $slug)
            ->where('status', Status::Active)
            ->with(['tyreModel' => fn ($query) => $query
                ->where('status', Status::Active)
                ->with(['brand' => fn ($brandQuery) => $brandQuery->where('status', Status::Active)])])
            ->first();

        abort_if($variant === null || $variant->tyreModel === null, 404);

        $variant->setAttribute('four_for_three', $this->fourForThreeFlags->forVariants([$variant])[$variant->id] ?? false);

        return new TyreVariantDetailResource($variant);
    }

    public function availability(TyreAvailabilityRequest $request, string $slug): JsonResponse
    {
        $variant = TyreVariant::query()
            ->where('slug', $slug)
            ->where('status', Status::Active)
            ->whereHas('tyreModel', fn (Builder $query) => $query->where('status', Status::Active))
            ->first();

        abort_if($variant === null, 404);

        $zone = ServiceZone::query()->find($request->integer('zone'));

        abort_if($zone === null, 404);

        $stockStatus = $this->stockCalculator->forVariant($variant->id, $zone);

        return response()->json([
            'data' => [
                'unit_price' => $variant->base_price,
                'promotional_price' => null,
                'currency' => 'AUD',
                'stock_status' => $stockStatus->value,
                // Real per-zone pricing/fees are a later-phase decision
                // (open decision #7) — placeholder for now.
                'service_fee' => 0,
            ],
        ]);
    }

    /**
     * @param  array{width: ?int, profile: ?int, rim_diameter: ?int}  $size
     * @return Builder<TyreVariant>
     */
    private function filteredQuery(TyreIndexRequest $request, array $size): Builder
    {
        $query = $this->baseQuery();

        if ($size['width'] !== null) {
            $query->where('tyre_variants.width', $size['width']);
        }
        if ($size['profile'] !== null) {
            $query->where('tyre_variants.profile', $size['profile']);
        }
        if ($size['rim_diameter'] !== null) {
            $query->where('tyre_variants.rim_diameter', $size['rim_diameter']);
        }

        $this->searchFilters->apply($query, $request->validated());

        return $this->applySort($query, $request->validated('sort') ?? 'newest');
    }

    /**
     * @return Builder<TyreVariant>
     */
    private function baseQuery(): Builder
    {
        return TyreVariant::query()
            ->join('tyre_models', 'tyre_models.id', '=', 'tyre_variants.tyre_model_id')
            ->join('brands', 'brands.id', '=', 'tyre_models.brand_id')
            ->where('tyre_variants.status', Status::Active)
            ->where('tyre_models.status', Status::Active)
            ->where('brands.status', Status::Active)
            ->select('tyre_variants.*')
            ->with('tyreModel.brand');
    }

    /**
     * @param  Builder<TyreVariant>  $query
     * @return Builder<TyreVariant>
     */
    private function applySort(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'price_asc' => $query->orderBy('tyre_variants.base_price'),
            'price_desc' => $query->orderByDesc('tyre_variants.base_price'),
            'name_asc' => $query->orderBy('tyre_models.name')->orderBy('tyre_variants.width'),
            default => $query
                ->orderByRaw('COALESCE(tyre_models.released_at, tyre_models.created_at) DESC')
                ->orderByDesc('tyre_variants.id'),
        };
    }

    /**
     * Paginate a staggered-mode side query and render it through the same
     * resource collection Laravel uses for the flat envelope, returning the
     * plain array so it can be nested under `data.front`/`data.rear` — the
     * one sanctioned exception to the flat pagination envelope.
     *
     * @param  Builder<TyreVariant>  $query
     * @return array<string, mixed>
     */
    private function paginatedResourceArray(Builder $query, ?ServiceZone $zone, int $perPage, string $pageName): array
    {
        $paginator = $query->paginate($perPage, ['*'], $pageName)->withQueryString();

        $this->attachListingFlags($paginator, $zone);
        $this->attachStockStatus($paginator, $zone);

        return TyreVariantResource::collection($paginator)->response()->getData(true);
    }

    /**
     * Sets the always-present listing flags (`four_for_three`) on each item.
     *
     * @param  LengthAwarePaginator<int, TyreVariant>  $paginator
     */
    private function attachListingFlags(LengthAwarePaginator $paginator, ?ServiceZone $zone): void
    {
        if ($paginator->isEmpty()) {
            return;
        }

        $flags = $this->fourForThreeFlags->forVariants($paginator->getCollection(), $zone?->id);

        $paginator->getCollection()->each(function (TyreVariant $variant) use ($flags): void {
            $variant->setAttribute('four_for_three', $flags[$variant->id] ?? false);
        });
    }

    /**
     * @param  LengthAwarePaginator<int, TyreVariant>  $paginator
     */
    private function attachStockStatus(LengthAwarePaginator $paginator, ?ServiceZone $zone): void
    {
        if ($zone === null || $paginator->isEmpty()) {
            return;
        }

        $statuses = $this->stockCalculator->forVariants($paginator->getCollection()->pluck('id'), $zone);

        $paginator->getCollection()->each(function (TyreVariant $variant) use ($statuses): void {
            $variant->stock_status = ($statuses[$variant->id] ?? null)?->value;
        });
    }

    /**
     * An absent `zone` param is a legitimate zone-less state and proceeds
     * unfiltered. A present-but-unresolvable `zone` id, however, must not
     * silently degrade to the same unfiltered result — that would violate
     * the "stale/tampered zone id degrades to re-check serviceability,
     * never an unfiltered nationwide result" rule (see
     * docs/architecture/02-api-contract.md), so it 404s instead, matching
     * the `availability` endpoint's handling of the same situation.
     */
    private function resolveZone(TyreIndexRequest|TyreLatestReleasesRequest|TyrePriceLaddersRequest $request, string $key): ?ServiceZone
    {
        if (! $request->filled($key)) {
            return null;
        }

        $zone = ServiceZone::query()->find($request->integer($key));

        abort_if($zone === null, 404);

        return $zone;
    }

    private function perPage(TyreIndexRequest|TyreLatestReleasesRequest $request): int
    {
        $requested = $request->filled('per_page') ? $request->integer('per_page') : self::DEFAULT_PER_PAGE;

        return min($requested, self::MAX_PER_PAGE);
    }
}
