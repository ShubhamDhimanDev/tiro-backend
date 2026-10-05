<?php

use App\Enums\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('technician_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained()->cascadeOnDelete();
            $table->foreignId('van_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_zone_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->time('shift_start');
            $table->time('shift_end');
            $table->string('status')->default(Status::Active->value);
            $table->timestamps();

            // Allows legitimate split shifts (two rows, same
            // technician/date, different start times) while blocking an
            // exact duplicate entry from a double-submit.
            $table->unique(['technician_id', 'date', 'shift_start']);

            // The slot-computation engine's primary lookup — see
            // docs/architecture/04-booking-capacity-engine.md step 1.
            $table->index(['service_zone_id', 'date']);
            // Dispatch board per-technician day view + reschedule's
            // "is this technician's replacement window still valid" check.
            $table->index(['technician_id', 'date']);
        });

        // MySQL 8 CHECK constraint — Blueprint has no first-class `check()`
        // helper, so this is added as a raw statement immediately after
        // table creation. Catches a bad direct DB write too, not just
        // app-layer validation.
        DB::statement(
            'ALTER TABLE technician_shifts ADD CONSTRAINT technician_shifts_shift_end_after_start CHECK (shift_end > shift_start)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('technician_shifts');
    }
};
