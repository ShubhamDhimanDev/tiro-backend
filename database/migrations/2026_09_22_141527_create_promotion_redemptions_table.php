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
        Schema::create('promotion_redemptions', function (Blueprint $table) {
            $table->id();
            // restrictOnDelete(), not cascade — a Promotion can have many
            // redemptions across many bookings/customers; deleting the
            // campaign must never silently destroy that discount/order
            // history, same "financial/history reference restricts" posture
            // as OrderLineItem.tyre_variant_id / Order.booking_id.
            $table->foreignId('promotion_id')->constrained()->restrictOnDelete();
            // cascadeOnDelete(), matching BookingLineItem.booking_id — a
            // redemption is a per-booking artifact with no independent
            // meaning once its booking is gone.
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('quantity');
            $table->integer('discount_amount')->nullable();
            $table->string('status');

            // Always set equal to the parent Booking.hold_expires_at at
            // creation time, never independently derived — see
            // docs/architecture/05-promotions-pricing.md. Deliberately NOT
            // registered in config('holds.models') — Booking::releaseHold()
            // cascades to release its own held children instead.
            $table->timestamp('hold_expires_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();

            // The stock-limit re-check's exact WHERE clause.
            $table->index(['promotion_id', 'status']);
            // The cascade-release lookup on the parent booking.
            $table->index('booking_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotion_redemptions');
    }
};
