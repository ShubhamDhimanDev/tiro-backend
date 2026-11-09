<?php

use App\Services\Commerce\SizePricingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Size bands now hold the fitting charge added to the tyre price, not an
     * all-in price. The launch 4-tyre figure for 16-17" (and the 13-15"
     * placeholder) goes up by $100 to match the client's fitting prices. Only
     * rows still holding the previously seeded values are touched, so anything
     * an admin has edited is left alone.
     */
    public function up(): void
    {
        foreach ([[16, 17, 36000, 46000], [13, 15, 35000, 45000]] as [$rimMin, $rimMax, $old, $new]) {
            $bandIds = DB::table('size_price_bands')->where('rim_min', $rimMin)->where('rim_max', $rimMax)->pluck('id');

            DB::table('size_price_tiers')
                ->whereIn('size_price_band_id', $bandIds)
                ->where('quantity', 4)
                ->where('total_price', $old)
                ->update(['total_price' => $new]);
        }

        SizePricingService::flush();
    }

    public function down(): void
    {
        // Data-only change; nothing to undo.
    }
};
