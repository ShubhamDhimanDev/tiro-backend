<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Models\SizePriceBand;
use App\Services\Commerce\SizePricingService;
use Illuminate\Database\Seeder;

/**
 * The launch fitting prices: the total fitting charge (GST-inclusive) added to
 * the tyre price for 1, 2, 3 and 4 tyres, never by brand. The client's fitting
 * is the same for every rim size ($200 / $300 / $400 / $400), so there is one
 * band covering every size. An admin can split it into rim bands later
 * (Promotions > Fitting pricing).
 *
 * Does nothing once any band exists, so re-seeding never overwrites prices an
 * admin has edited.
 */
class SizePriceSeeder extends Seeder
{
    /** @var list<array{name: string, rim_min: int, rim_max: int, totals: list<int>}> */
    private const BANDS = [
        ['name' => 'All sizes', 'rim_min' => 10, 'rim_max' => 30, 'totals' => [20000, 30000, 40000, 40000]],
    ];

    public function run(): void
    {
        if (SizePriceBand::query()->exists()) {
            return;
        }

        foreach (self::BANDS as $definition) {
            $band = SizePriceBand::query()->create([
                'name' => $definition['name'],
                'rim_min' => $definition['rim_min'],
                'rim_max' => $definition['rim_max'],
                'status' => Status::Active,
            ]);

            foreach ($definition['totals'] as $index => $total) {
                $band->tiers()->create(['quantity' => $index + 1, 'total_price' => $total]);
            }
        }

        // Seeders may run with model events off, so don't rely on the observers.
        SizePricingService::flush();
    }
}
