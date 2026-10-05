<?php

namespace App\Services\Bookings;

use App\Enums\DurationRuleAppliesTo;
use App\Models\DurationRule;
use App\Models\TyreVariant;
use Illuminate\Support\Collection;

/**
 * Implements the exact duration formula from
 * docs/architecture/04-booking-capacity-engine.md's "Job duration" section.
 * Never hardcode a duration — every term is looked up from an admin-tunable
 * {@see DurationRule} row, and a missing row is a hard error (see
 * {@see DurationRule::minutesFor()}), not a silent 0.
 */
class DurationCalculationService
{
    /**
     * @param  Collection<int, BookingLineItemInput>  $items
     * @param  list<string>  $addons  Customer-selected `booking_addon` keys
     *                                only (e.g. `["alignment"]`) — never
     *                                `staggered`, which is derived below.
     */
    public function calculate(Collection $items, array $addons): int
    {
        $minutes = DurationRule::minutesFor(DurationRuleAppliesTo::Base, 'setup_overhead');

        $variantsById = $items->isEmpty()
            ? collect()
            : TyreVariant::query()
                ->whereIn('id', $items->pluck('tyreVariantId')->unique())
                ->with('tyreModel')
                ->get()
                ->keyBy('id');

        foreach ($items as $item) {
            /** @var TyreVariant $variant */
            $variant = $variantsById->get($item->tyreVariantId)
                ?? TyreVariant::query()->with('tyreModel')->findOrFail($item->tyreVariantId);

            $category = $variant->tyreModel->category->value;
            $minutes += DurationRule::minutesFor(DurationRuleAppliesTo::TyreCategory, $category) * $item->quantity;

            if ($variant->tyreModel->run_flat) {
                $minutes += DurationRule::minutesFor(DurationRuleAppliesTo::TyreAddon, 'run_flat') * $item->quantity;
            }
        }

        foreach (array_unique($addons) as $addon) {
            $minutes += DurationRule::minutesFor(DurationRuleAppliesTo::BookingAddon, $addon);
        }

        $distinctPositions = $items->map(fn (BookingLineItemInput $item) => $item->position->value)->unique();

        if ($distinctPositions->count() > 1) {
            $minutes += DurationRule::minutesFor(DurationRuleAppliesTo::BookingAddon, 'staggered');
        }

        return $minutes;
    }
}
