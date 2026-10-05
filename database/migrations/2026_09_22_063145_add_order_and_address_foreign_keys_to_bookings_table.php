<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `bookings.order_id`/`bookings.address_id` have existed, unconstrained,
     * since the Phase 3 `create_bookings_table` migration — that migration's
     * own comments reserved the columns for this exact follow-up, deferred
     * until `orders`/`addresses` actually existed. `nullOnDelete()` on both,
     * matching their existing nullability.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->foreign('address_id')->references('id')->on('addresses')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropForeign(['address_id']);
        });
    }
};
