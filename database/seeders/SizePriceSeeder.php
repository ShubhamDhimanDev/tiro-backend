<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Models\SizePriceBand;
use App\Services\Commerce\SizePricingService;
use Illuminate\Database\Seeder;

/**
 * The launch fitting prices: the total fitting charge (GST-inclusive) added to
 * the tyre price for 1, 2, 3 and 4 tyres, by rim size and never by brand.
 *
 * 16-17" and 18-20" are the client's figures. 13-15" is the 16" fitting less
 * $10 per tier, a placeholder until the client confirms; edit it in the admin
 * panel (Promotions > Size pricing). Sizes above 20" get no fitting charge.
 *
 * Does nothing once any band exists, so re-seeding never overwrites prices an
 * admin has edited.
 */
class SizePriceSeeder extends Seeder
{
    /** @var list<array{name: string, rim_min: int, rim_max: int, totals: list<int>}> */
    private const BANDS = [
        ['name' => '13–15 inch', 'rim_min' => 13, 'rim_max' => 15, 'totals' => [15000, 25000, 35000, 45000]],
        ['name' => '16–17 inch', 'rim_min' => 16, 'rim_max' => 17, 'totals' => [16000, 26000, 36000, 46000]],
        ['name' => '18–20 inch', 'rim_min' => 18, 'rim_max' => 20, 'totals' => [17000, 27000, 37000, 37000]],
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
