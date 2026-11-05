<?php

use App\Enums\Status;
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
        // Brand-agnostic, size-only pricing: a rim-diameter band with a total
        // price (GST-inclusive, fitting included, cents) for 1..N tyres.
        Schema::create('size_price_bands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedTinyInteger('rim_min');
            $table->unsignedTinyInteger('rim_max');
            $table->string('status')->default(Status::Active->value);
            $table->timestamps();

            $table->index(['status', 'rim_min', 'rim_max']);
        });

        Schema::create('size_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('size_price_band_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('quantity');
            $table->unsignedInteger('total_price');
            $table->timestamps();

            $table->unique(['size_price_band_id', 'quantity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('size_price_tiers');
        Schema::dropIfExists('size_price_bands');
    }
};
