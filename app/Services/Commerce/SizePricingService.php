<?php

namespace App\Services\Commerce;

use App\Enums\Status;
use App\Models\SizePriceBand;
use App\Models\TyreVariant;
use App\Observers\SizePriceObserver;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Brand-agnostic, size-only pricing. A tyre whose rim diameter falls inside an
 * active {@see SizePriceBand} is priced by the band, not by its own
 * `base_price`: the order's tyres in that band are priced together as ONE
 * total taken from the band's tier for that quantity (1 tyre = $160, 2 =
 * $260, 3 = $360, 4 = $360…). Prices are GST-inclusive and include fitting.
 *
 * Tyres in different bands are priced per band (2 x 17" + 2 x 19" = the
 * 2-tyre total of each band added together). A tyre outside every band keeps
 * pricing at `base_price x quantity`, so a size nobody has priced yet still
 * works.
 *
 * Bands are cached forever and flushed by {@see SizePriceObserver},
 * so pricing a cart costs no band queries.
 */
class SizePricingService
{
    /** The most tyres one order may hold (the largest tier an admin can price). */
    public const MAX_TYRES = 4;

    private const CACHE_KEY = 'size-pricing:active-bands';

    /**
     * Validation rule for an `items` array: an order holds at most
     * {@see self::MAX_TYRES} tyres in total across all its lines (larger jobs
     * are a phone call).
     */
    public static function maxTyresRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $total = is_array($value)
                ? array_sum(array_map(fn ($item): int => is_array($item) ? (int) ($item['quantity'] ?? 0) : 0, $value))
                : 0;

            if ($total > self::MAX_TYRES) {
                $fail('An order can hold at most '.self::MAX_TYRES.' tyres. Please call us for larger jobs.');
            }
        };
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Active bands that have a 1-tyre price, as plain arrays (cache-safe).
     * `tiers` maps quantity => total price (cents).
     *
     * @return list<array{id: int, rim_min: int, rim_max: int, tiers: array<int, int>}>
     */
    public function activeBands(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $bands = [];

            $models = SizePriceBand::query()
                ->where('status', Status::Active)
                ->with('tiers')
                ->orderBy('rim_min')
                ->get();

            foreach ($models as $band) {
                $tiers = [];

                foreach ($band->tiers as $tier) {
                    $tiers[$tier->quantity] = $tier->total_price;
                }

                // A band with no 1-tyre price can't price anything.
                if (isset($tiers[1])) {
                    $bands[] = ['id' => $band->id, 'rim_min' => $band->rim_min, 'rim_max' => $band->rim_max, 'tiers' => $tiers];
                }
            }

            return $bands;
        });
    }

    /**
     * @return array{id: int, rim_min: int, rim_max: int, tiers: array<int, int>}|null
     */
    public function bandForRim(int $rimDiameter): ?array
    {
        foreach ($this->activeBands() as $band) {
            if ($rimDiameter >= $band['rim_min'] && $rimDiameter <= $band['rim_max']) {
                return $band;
            }
        }

        return null;
    }

    /** The 1-tyre price (cents) for a rim diameter, or null when no band covers it. */
    public function singleTyrePrice(int $rimDiameter): ?int
    {
        return $this->bandForRim($rimDiameter)['tiers'][1] ?? null;
    }

    /**
     * Total (cents) for `$quantity` tyres in a band. Beyond the largest tier
     * (only reachable by a caller that skipped the {@see self::MAX_TYRES}
     * validation) each extra tyre is charged at the 1-tyre price, never free.
     *
     * @param  array{tiers: array<int, int>}  $band
     */
    public function totalFor(array $band, int $quantity): int
    {
        $tiers = $band['tiers'];
        $largest = max(1, ...array_keys($tiers));

        if ($quantity <= $largest) {
            return $tiers[$quantity] ?? $tiers[1] * $quantity;
        }

        return $tiers[$largest] + ($quantity - $largest) * $tiers[1];
    }

    /**
     * Price every cart line. Keyed by line index (the position in `$items`):
     * `value` is the line's exact total (cents), `unit` its per-tyre average
     * (rounded, for display and promotions), `banded` whether a size band
     * priced it.
     *
     * A band's total is shared out across that band's lines in proportion to
     * quantity, with the rounding remainder on the last line, so the lines add
     * up to the band total to the cent.
     *
     * @param  Collection<int, CartItemInput>  $items
     * @param  Collection<int, TyreVariant>  $variantsById
     * @return array<int, array{value: int, unit: int, banded: bool}>
     */
    public function priceLines(Collection $items, Collection $variantsById): array
    {
        $items = $items->values();
        $lines = [];
        /** @var array<int, array{band: array{id: int, rim_min: int, rim_max: int, tiers: array<int, int>}, lines: list<int>, quantity: int}> $groups */
        $groups = [];

        foreach ($items as $index => $item) {
            $variant = $variantsById->get($item->tyreVariantId);

            if ($variant === null) {
                continue;
            }

            $band = $this->bandForRim($variant->rim_diameter);

            if ($band === null) {
                $lines[$index] = ['value' => $variant->base_price * $item->quantity, 'unit' => $variant->base_price, 'banded' => false];

                continue;
            }

            $groups[$band['id']] ??= ['band' => $band, 'lines' => [], 'quantity' => 0];
            $groups[$band['id']]['lines'][] = $index;
            $groups[$band['id']]['quantity'] += $item->quantity;
        }

        foreach ($groups as $group) {
            $total = $this->totalFor($group['band'], $group['quantity']);
            $allocated = 0;
            $lastPosition = array_key_last($group['lines']);

            foreach ($group['lines'] as $position => $index) {
                $quantity = $items[$index]->quantity;
                $value = $position === $lastPosition
                    ? $total - $allocated
                    : intdiv($total * $quantity, $group['quantity']);

                $allocated += $value;
                $lines[$index] = ['value' => $value, 'unit' => (int) round($value / max(1, $quantity)), 'banded' => true];
            }
        }

        ksort($lines);

        return $lines;
    }

    /**
     * Make every variant's `base_price` the 1-tyre price of its band, so the
     * listing, "from $" and price sort/filter — which all read `base_price` —
     * agree with the band price. Returns the number of variants updated.
     */
    public function syncVariantPrices(): int
    {
        $updated = 0;

        foreach ($this->activeBands() as $band) {
            $updated += DB::table('tyre_variants')
                ->whereBetween('rim_diameter', [$band['rim_min'], $band['rim_max']])
                ->where('base_price', '!=', $band['tiers'][1])
                ->update(['base_price' => $band['tiers'][1]]);
        }

        return $updated;
    }
}
