<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            // Guest until matched — same create-or-match-by-email semantics
            // as guest bookings. See docs/architecture/08-customer-auth-otp.md.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            // Required, not nullable — every Order originates from exactly
            // one already-resolved Booking (asymmetric with the nullable
            // Booking.order_id, deliberately — see
            // docs/architecture/01-data-model.md's `Order` section).
            $table->foreignId('booking_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('address_id')->constrained()->restrictOnDelete();
            $table->string('status')->default(OrderStatus::PendingPayment->value);
            $table->string('payment_status')->default(PaymentStatus::Pending->value);
            $table->integer('subtotal');
            $table->integer('discount_total')->default(0);
            $table->integer('tax_total');
            $table->integer('service_fee_total')->default(0);
            $table->integer('grand_total');
            $table->string('currency', 3)->default('AUD');
            // Not nullable — unlike Booking.idempotency_key, an Order is
            // never created outside the Idempotency-Key-guarded
            // POST /api/v1/orders flow. See
            // docs/architecture/01-data-model.md's `Order` section.
            $table->string('idempotency_key')->unique();
            // Guest-continuity secret, hashed at rest — mirrors
            // Booking.manage_token_hash exactly (see
            // docs/architecture/02-api-contract.md's "One-time secrets
            // under idempotent replay" convention).
            $table->string('guest_token_hash')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamps();

            // Account order history (Phase 7).
            $table->index('customer_id');
            $table->index('guest_token_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
