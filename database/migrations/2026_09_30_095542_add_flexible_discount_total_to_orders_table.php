<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6a: the flexible-booking discount is order-level (not per line),
     * so it is stamped on the order. It is already included in
     * `discount_total`; this column just lets the response label it.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->integer('flexible_discount_total')->default(0)->after('discount_total');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('flexible_discount_total');
        });
    }
};
