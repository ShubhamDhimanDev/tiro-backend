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
        Schema::create('booking_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tyre_variant_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('quantity');
            // Reuses App\Enums\VehicleFitmentPosition [front|rear|all] — do
            // not declare a second, near-identical enum. See
            // docs/architecture/01-data-model.md's BookingLineItem section.
            $table->string('position');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_line_items');
    }
};
