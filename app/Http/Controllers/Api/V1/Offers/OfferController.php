<?php

namespace App\Http\Controllers\Api\V1\Offers;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\OfferResource;
use App\Models\Promotion;
use App\Services\Promotions\OfferTargetResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public, read-only offers on top of the existing promotions engine — no
 * second engine. A promotion is a public offer when it is `is_public`, has a
 * `slug`, is `active`, today falls inside `starts_at..ends_at`, and its
 * `usage_limit` (if any) is not exhausted. Everything else is omitted from
 * the list and 404s on the detail route.
 */
class OfferController extends Controller
{
    public function __construct(private readonly OfferTargetResolver $targets) {}

    public function index(): AnonymousResourceCollection
    {
        $promotions = $this->visibleOffers()
            ->orderBy('ends_at')
            ->orderBy('id')
            ->get();

        return OfferResource::collection($this->withTargets($promotions));
    }

    public function show(string $slug): OfferResource
    {
        $promotion = $this->visibleOffers()->where('slug', $slug)->first();

        abort_if($promotion === null, 404);

        return new OfferResource($this->withTargets($promotion->newCollection([$promotion]))->first());
    }

    /**
     * @return Builder<Promotion>
     */
    private function visibleOffers(): Builder
    {
        $today = now()->toDateString();

        return Promotion::query()
            ->where('is_public', true)
            ->whereNotNull('slug')
            ->where('status', Status::Active)
            ->whereDate('starts_at', '<=', $today)
            ->whereDate('ends_at', '>=', $today)
            ->where(fn (Builder $query) => $query->whereNull('usage_limit')->orWhereColumn('usage_count', '<', 'usage_limit'))
            ->with('eligibilities');
    }

    /**
     * @param  Collection<int, Promotion>  $promotions
     * @return Collection<int, Promotion>
     */
    private function withTargets(Collection $promotions): Collection
    {
        $resolved = $this->targets->resolve($promotions);

        foreach ($promotions as $promotion) {
            $promotion->setAttribute('offer_target', $resolved[$promotion->id]);
        }

        return $promotions;
    }
}
