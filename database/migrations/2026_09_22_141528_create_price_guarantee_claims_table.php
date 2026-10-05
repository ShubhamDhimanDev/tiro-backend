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
        Schema::create('price_guarantee_claims', function (Blueprint $table) {
            $table->id();
            // restrictOnDelete() on both required FKs — a claim is a
            // customer-submitted, admin-reviewed record with its own
            // audit/history significance; deleting the referenced Customer
            // or TyreVariant must never silently cascade it away, same
            // posture as OrderLineItem.tyre_variant_id.
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('competitor_url');
            $table->integer('competitor_price');
            $table->foreignId('tyre_variant_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('pending');
            $table->integer('approved_discount_amount')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // Own-claims list (customer_id, status) and the cart/order-time
            // lookup for an approved-unredeemed claim matching the current
            // customer+tyre_variant_id.
            $table->index(['customer_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_guarantee_claims');
    }
};
