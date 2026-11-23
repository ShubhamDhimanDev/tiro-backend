<?php

use App\Enums\Status;
use App\Services\Commerce\SizePricingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The client's fitting is now the same for every rim size: $200 / $300 /
     * $400 / $400 for 1 / 2 / 3 / 4 tyres. The existing rim bands collapse
     * into one band covering every size (the oldest band is kept and
     * widened, the rest removed), or one is created on a database that has
     * none. Edits made to the old bands in the admin are replaced.
     */
    public function up(): void
    {
        $totals = [20000, 30000, 40000, 40000];
        $keepId = DB::table('size_price_bands')->orderBy('id')->value('id');

        if ($keepId === null) {
            $keepId = DB::table('size_price_bands')->insertGetId([
                'name' => 'All sizes',
                'rim_min' => 10,
                'rim_max' => 30,
                'status' => Status::Active->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('size_price_bands')->where('id', $keepId)->update([
                'name' => 'All sizes',
                'rim_min' => 10,
                'rim_max' => 30,
                'status' => Status::Active->value,
                'updated_at' => now(),
            ]);

            $otherIds = DB::table('size_price_bands')->where('id', '!=', $keepId)->pluck('id');
            DB::table('size_price_tiers')->whereIn('size_price_band_id', $otherIds)->delete();
            DB::table('size_price_bands')->whereIn('id', $otherIds)->delete();
        }

        DB::table('size_price_tiers')->where('size_price_band_id', $keepId)->where('quantity', '>', count($totals))->delete();

        foreach ($totals as $index => $total) {
            DB::table('size_price_tiers')->updateOrInsert(
                ['size_price_band_id' => $keepId, 'quantity' => $index + 1],
                ['total_price' => $total, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        SizePricingService::flush();
    }

    public function down(): void
    {
        // Data-only change; nothing to undo.
    }
};
