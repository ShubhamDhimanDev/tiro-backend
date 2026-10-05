<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // PayPal only — populated once a PayPal Order's capture step
            // succeeds. `gateway_reference` stays the PayPal *Order* id
            // throughout (mirrors Stripe's PaymentIntent-id role — set at
            // POST /api/v1/orders time); this column exists purely to give
            // RefundService the value PayPal's refund API actually needs (a
            // refund targets the capture, not the order). Nullable-unique —
            // MySQL permits multiple NULLs under a unique index, so Stripe
            // rows/pre-capture PayPal rows coexist fine. See
            // docs/architecture/01-data-model.md's `Payment` section.
            $table->string('gateway_capture_reference')->nullable()->unique()->after('gateway_reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('gateway_capture_reference');
        });
    }
};
