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
 * 16-17" and 18" and over are the client's figures. Under 16" is the 16"
 * fitting less $10 per tier (the 4-tyre figure follows 16-17"), still to be
 * confirmed by the client; edit it in the admin panel (Promotions > Fitting
 * pricing).
 *
 * Does nothing once any band exists, so re-seeding never overwrites prices an
 * admin has edited.
 */
class SizePriceSeeder extends Seeder
{
    /** @var list<array{name: string, rim_min: int, rim_max: int, totals: list<int>}> */
    private const BANDS = [
        ['name' => 'Under 16 inch', 'rim_min' => 10, 'rim_max' => 15, 'totals' => [15000, 25000, 35000, 36000]],
        ['name' => '16–17 inch', 'rim_min' => 16, 'rim_max' => 17, 'totals' => [16000, 26000, 36000, 37000]],
        ['name' => '18 inch and over', 'rim_min' => 18, 'rim_max' => 30, 'totals' => [17000, 27000, 37000, 38000]],
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
