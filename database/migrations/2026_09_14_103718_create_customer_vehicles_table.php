<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Schema-only this phase — the saved-vehicle feature itself ships in
     * Phase 7. See docs/architecture/01-data-model.md's CustomerVehicle
     * section.
     */
    public function up(): void
    {
        Schema::create('customer_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('rego')->nullable();
            // Deliberately a free-text AU state/territory code, NOT a FK to
            // `states` — a customer's rego state is descriptive metadata and
            // can legitimately be a state Tiro doesn't service. See
            // docs/architecture/01-data-model.md.
            $table->string('state')->nullable();
            $table->string('vin')->nullable();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            // Denormalized snapshot of the resolved fitment at save time —
            // not a live re-read of `vehicle_fitments`, so a later admin
            // correction doesn't silently rewrite what a customer already
            // saved/ordered against. Not nullable per the data model doc
            // (only rego/state/vin/vehicle_id are marked nullable there) —
            // every saved vehicle carries at least a fitment snapshot, even
            // when it wasn't resolved from a catalogued `Vehicle` row.
            $table->json('saved_fitment');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_vehicles');
    }
};
