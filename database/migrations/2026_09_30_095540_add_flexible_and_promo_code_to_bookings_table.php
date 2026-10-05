<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6a: `is_flexible` (customer accepts any window in the day for a
     * discount; the booking still holds a concrete, capacity-checked slot)
     * and `promo_code` (the typed code validated at hold time, so
     * `POST /orders`' recompute re-applies it).
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('is_flexible')->default(false)->after('addons');
            $table->string('promo_code', 40)->nullable()->after('is_flexible');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['is_flexible', 'promo_code']);
        });
    }
};
