<?php

use App\Enums\Status;
use App\Services\Commerce\SizePricingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The client's updated fitting prices (GST-inclusive, 1-4 tyres):
     * 16-17": 160/260/360/370, 18" and over: 170/270/370/380, and under 16"
     * follows 16" less $10 (150/250/350/360).
     *
     * Bands are matched by their lower rim size (13 or 10 = the under-16"
     * band, 16, 18). The under-16" and 18+ bands are also widened to cover
     * every size. A band that does not exist yet is created, so this is
     * correct on a database that was never seeded.
     */
    public function up(): void
    {
        $bands = [
            ['match' => [10, 13], 'name' => 'Under 16 inch', 'rim_min' => 10, 'rim_max' => 15, 'totals' => [15000, 25000, 35000, 36000]],
            ['match' => [16], 'name' => '16–17 inch', 'rim_min' => 16, 'rim_max' => 17, 'totals' => [16000, 26000, 36000, 37000]],
            ['match' => [18], 'name' => '18 inch and over', 'rim_min' => 18, 'rim_max' => 30, 'totals' => [17000, 27000, 37000, 38000]],
        ];

        foreach ($bands as $band) {
            $id = DB::table('size_price_bands')->whereIn('rim_min', $band['match'])->orderBy('id')->value('id');

            if ($id === null) {
                $id = DB::table('size_price_bands')->insertGetId([
                    'name' => $band['name'],
                    'rim_min' => $band['rim_min'],
                    'rim_max' => $band['rim_max'],
                    'status' => Status::Active->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('size_price_bands')->where('id', $id)->update([
                    'name' => $band['name'],
                    'rim_min' => $band['rim_min'],
                    'rim_max' => $band['rim_max'],
                    'status' => Status::Active->value,
                    'updated_at' => now(),
                ]);
            }

            foreach ($band['totals'] as $index => $total) {
                DB::table('size_price_tiers')->updateOrInsert(
                    ['size_price_band_id' => $id, 'quantity' => $index + 1],
                    ['total_price' => $total, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }

        SizePricingService::flush();
    }

    public function down(): void
    {
        // Data-only change; nothing to undo.
    }
};
