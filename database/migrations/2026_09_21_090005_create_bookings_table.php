<?php

use App\Enums\BookingStatus;
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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            // `orders` (Phase 4) doesn't exist yet — column reserved now
            // (matches docs/architecture/01-data-model.md's field list) so
            // Phase 4 doesn't need a column-adding migration, but the FK
            // constraint itself is deferred to whenever that table is
            // created.
            $table->foreignId('order_id')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_zone_id')->constrained()->restrictOnDelete();
            // `addresses` (Phase 4) doesn't exist yet either — same
            // reserved-column-now, FK-later treatment as order_id above.
            // Backfilled onto the row when the order is created (Phase 4),
            // not at hold-creation time — see the data-model doc.
            $table->foreignId('address_id')->nullable();
            $table->date('scheduled_date');
            $table->time('slot_start');
            $table->time('slot_end');
            $table->foreignId('technician_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('van_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default(BookingStatus::PendingHold->value);
            $table->unsignedSmallInteger('duration_minutes');
            // Customer-selected booking_addon keys only (e.g. ["alignment"])
            // — never where run_flat/staggered live, those are derived. See
            // docs/architecture/01-data-model.md.
            $table->json('addons')->nullable();
            $table->text('access_notes')->nullable();
            $table->timestamp('hold_expires_at')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('manage_token_hash')->nullable();
            $table->integer('cancellation_fee_amount')->nullable();
            $table->timestamps();

            // Slot computation's per-technician occupied-window lookup.
            $table->index(['technician_id', 'scheduled_date']);
            // Van max_jobs_per_day cap check.
            $table->index(['van_id', 'scheduled_date']);
            // Dispatch board filtering (zone/date/status).
            $table->index(['service_zone_id', 'scheduled_date', 'status']);
            // The expired-hold sweep's exact WHERE clause.
            $table->index(['status', 'hold_expires_at']);
            // Account booking history (Phase 7).
            $table->index('customer_id');
            $table->index('manage_token_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
