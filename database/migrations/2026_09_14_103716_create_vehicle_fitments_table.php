<?php

use App\Enums\Status;
use App\Enums\VehicleFitmentConfidence;
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
        Schema::create('vehicle_fitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('position');
            // Same column types/semantics as TyreVariant's equivalent
            // columns (see docs/architecture/01-data-model.md's Catalogue
            // section) so a resolved fitment hands off to GET
            // /api/v1/tyres's query params without type coercion.
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('profile');
            $table->unsignedSmallInteger('rim_diameter');
            $table->string('load_index')->nullable();
            $table->string('speed_rating')->nullable();
            $table->boolean('is_staggered');
            $table->string('source');
            $table->string('confidence')->default(VehicleFitmentConfidence::Confirmed->value);
            $table->text('notes')->nullable();
            $table->string('status')->default(Status::Active->value);
            $table->timestamps();

            $table->unique(['vehicle_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_fitments');
    }
};
