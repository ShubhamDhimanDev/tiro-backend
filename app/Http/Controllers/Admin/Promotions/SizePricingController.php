<?php

namespace App\Http\Controllers\Admin\Promotions;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Promotions\SizePriceBandRequest;
use App\Models\SizePriceBand;
use App\Models\TyreVariant;
use App\Observers\SizePriceObserver;
use App\Services\Commerce\SizePricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD for brand-agnostic size pricing: rim-diameter bands, each with a total
 * price for 1..4 tyres (GST-inclusive, fitting included). Gated on
 * `promotions.manage` for writes, `promotions.view` for the page, like
 * `PriceRule`. `AuditLog` rows are written by {@see SizePriceObserver} — do
 * not add explicit ones here.
 *
 * Every save re-syncs `tyre_variants.base_price` to the band's 1-tyre price
 * (see {@see SizePricingService::syncVariantPrices()}) so listings agree.
 */
class SizePricingController extends Controller
{
    public function __construct(private readonly SizePricingService $sizePricing) {}

    public function index(): Response
    {
        $bands = SizePriceBand::query()
            ->with('tiers:id,size_price_band_id,quantity,total_price')
            ->orderBy('rim_min')
            ->get();

        $activeBands = $bands->where('status', Status::Active);

        // Rim sizes the catalogue stocks that no active band prices (they keep their own `base_price`).
        $unpricedRims = TyreVariant::query()
            ->distinct()
            ->orderBy('rim_diameter')
            ->pluck('rim_diameter')
            ->reject(fn (int $rim): bool => $activeBands->contains(fn (SizePriceBand $band): bool => $rim >= $band->rim_min && $rim <= $band->rim_max))
            ->values();

        return Inertia::render('promotions/size-pricing/index', [
            'bands' => $bands,
            'unpricedRims' => $unpricedRims,
            'maxTyres' => SizePricingService::MAX_TYRES,
        ]);
    }

    public function store(SizePriceBandRequest $request): RedirectResponse
    {
        $this->save(new SizePriceBand, $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Size price band created.')]);

        return back();
    }

    public function update(SizePriceBandRequest $request, SizePriceBand $sizePriceBand): RedirectResponse
    {
        $this->save($sizePriceBand, $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Size price band updated.')]);

        return back();
    }

    public function destroy(SizePriceBand $sizePriceBand): RedirectResponse
    {
        DB::transaction(function () use ($sizePriceBand): void {
            // Tiers cascade in the database, which fires no model events, so delete them through Eloquent to audit-log each.
            $sizePriceBand->tiers->each->delete();
            $sizePriceBand->delete();
        });

        SizePricingService::flush();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Size price band deleted.')]);

        return back();
    }

    private function save(SizePriceBand $band, SizePriceBandRequest $request): void
    {
        $data = $request->validated();

        DB::transaction(function () use ($band, $data): void {
            $band->fill(collect($data)->only(['name', 'rim_min', 'rim_max', 'status'])->all())->save();

            $quantities = [];

            foreach ($data['tiers'] as $tier) {
                $quantities[] = (int) $tier['quantity'];
                $band->tiers()->updateOrCreate(
                    ['quantity' => (int) $tier['quantity']],
                    ['total_price' => (int) $tier['total_price']],
                );
            }

            $band->tiers()->whereNotIn('quantity', $quantities)->get()->each->delete();
        });

        // Flush again now the transaction has committed, then make listings follow the new prices.
        SizePricingService::flush();
        $this->sizePricing->syncVariantPrices();
    }
}
