<?php

namespace App\Services\Catalogue;

use App\Enums\PromotionType;
use App\Enums\Status;
use App\Models\Promotion;
use App\Models\TyreVariant;
use Illuminate\Support\Collection;

/**
 * Decides which tyres wear the "4 for 3" sticker: an active, in-window,
 * auto-applied (no code), non-exhausted `four_for_three` promotion whose
 * eligibility covers the tyre (and the zone, when one is given). Promotions
 * are loaded once per instance, so listing a page of tyres costs one query.
 * Code-gated promotions never set the flag (they only apply when typed).
 */
class FourForThreeFlagService
{
    /** @var Collection<int, Promotion>|null */
    private ?Collection $promotions = null;

    /**
     * @param  iterable<int, TyreVariant>  $variants  with `tyreModel` loaded
     * @return array<int, bool> keyed by variant id
     */
    public function forVariants(iterable $variants, ?int $zoneId = null): array
    {
        $promotions = $this->promotions();
        $flags = [];

        foreach ($variants as $variant) {
            $flags[$variant->id] = $promotions->contains(fn (Promotion $promotion): bool => $promotion->eligibilities->contains(
                fn ($eligibility): bool => $eligibility->matchesZone($zoneId) && $eligibility->matchesVariant($variant),
            ));
        }

        return $flags;
    }

    /**
     * @return Collection<int, Promotion>
     */
    private function promotions(): Collection
    {
        return $this->promotions ??= Promotion::query()
            ->where('status', Status::Active)
            ->where('type', PromotionType::FourForThree)
            ->whereNull('code')
            ->whereDate('starts_at', '<=', now()->toDateString())
            ->whereDate('ends_at', '>=', now()->toDateString())
            ->with('eligibilities')
            ->get()
            ->filter(fn (Promotion $promotion): bool => $promotion->usage_limit === null || $promotion->usage_count < $promotion->usage_limit)
            ->values();
    }
}
