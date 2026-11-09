<?php

namespace App\Services\Commerce;

use App\Enums\Status;
use App\Models\SizePriceBand;
use App\Models\TyreVariant;
use App\Observers\SizePriceObserver;
use Closure;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Size-based fitting pricing. The tyre keeps its own `base_price`; on top of it
 * the customer pays a fitting charge set by the tyre's rim size and the number
 * of tyres in the order, taken from the rim band's tier (18-20": 170, 270, 370,
 * 370 for 1, 2, 3, 4 tyres; 16-17": 160, 260, 360, 460). Fitting is never shown
 * as its own price: it is folded into the line, so a tyre reads as one
 * GST-inclusive, fitted price. The fitting depends on size only, never on brand.
 *
 * Tyres in the same band share one fitting total for their combined quantity;
 * different bands add up separately. A tyre outside every band has no fitting
 * charge.
 *
 * Bands are cached forever and flushed by {@see SizePriceObserver}, so pricing
 * a cart costs no band queries.
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
     * `tiers` maps quantity => fitting total for that many tyres (cents).
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

    /** The fitting charge (cents) for one tyre of this rim diameter; 0 when no band covers it. */
    public function singleTyreFitting(int $rimDiameter): int
    {
        return $this->bandForRim($rimDiameter)['tiers'][1] ?? 0;
    }

    /**
     * SQL for a variant's catalogue price: its own `base_price` plus the 1-tyre
     * fitting of its rim band. This is the price the storefront shows, so price
     * sort and filter must use it rather than the bare column. Built from
     * integers only. An `Expression`, so it drops into `orderBy`/`where`/`select`.
     */
    public function displayPriceSql(string $variantTable = 'tyre_variants'): Expression
    {
        $cases = '';

        foreach ($this->activeBands() as $band) {
            $cases .= sprintf(' WHEN %1$s.rim_diameter BETWEEN %2$d AND %3$d THEN %4$d', $variantTable, $band['rim_min'], $band['rim_max'], $band['tiers'][1]);
        }

        return new Expression($cases === ''
            ? "{$variantTable}.base_price"
            : "({$variantTable}.base_price + CASE{$cases} ELSE 0 END)");
    }

    /**
     * Fitting total (cents) for `$quantity` tyres in a band. Beyond the largest
     * tier (only reachable by a caller that skipped the {@see self::MAX_TYRES}
     * validation) each extra tyre is charged the 1-tyre fitting, never free.
     *
     * @param  array{tiers: array<int, int>}  $band
     */
    public function fittingFor(array $band, int $quantity): int
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
     * `value` is the line's exact total (cents) = tyre price x quantity plus
     * its share of the fitting, `unit` that total per tyre (rounded, for
     * display), `fitting` the fitting share alone.
     *
     * A band's fitting total is shared across that band's lines in proportion
     * to quantity, with the rounding remainder on the last line, so the lines
     * add up to the exact fitting total.
     *
     * @param  Collection<int, CartItemInput>  $items
     * @param  Collection<int, TyreVariant>  $variantsById
     * @return array<int, array{value: int, unit: int, fitting: int}>
     */
    public function priceLines(Collection $items, Collection $variantsById): array
    {
        $items = $items->values();
        $fittings = [];
        /** @var array<int, array{band: array{id: int, rim_min: int, rim_max: int, tiers: array<int, int>}, lines: list<int>, quantity: int}> $groups */
        $groups = [];

        foreach ($items as $index => $item) {
            $variant = $variantsById->get($item->tyreVariantId);

            if ($variant === null) {
                continue;
            }

            $fittings[$index] = 0;
            $band = $this->bandForRim($variant->rim_diameter);

            if ($band === null) {
                continue;
            }

            $groups[$band['id']] ??= ['band' => $band, 'lines' => [], 'quantity' => 0];
            $groups[$band['id']]['lines'][] = $index;
            $groups[$band['id']]['quantity'] += $item->quantity;
        }

        foreach ($groups as $group) {
            $total = $this->fittingFor($group['band'], $group['quantity']);
            $allocated = 0;
            $lastPosition = array_key_last($group['lines']);

            foreach ($group['lines'] as $position => $index) {
                $share = $position === $lastPosition
                    ? $total - $allocated
                    : intdiv($total * $items[$index]->quantity, $group['quantity']);

                $allocated += $share;
                $fittings[$index] = $share;
            }
        }

        $lines = [];

        foreach ($fittings as $index => $fitting) {
            $quantity = $items[$index]->quantity;
            $value = $variantsById->get($items[$index]->tyreVariantId)->base_price * $quantity + $fitting;

            $lines[$index] = ['value' => $value, 'unit' => (int) round($value / max(1, $quantity)), 'fitting' => $fitting];
        }

        ksort($lines);

        return $lines;
    }
}
