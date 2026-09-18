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
        Schema::create('service_zone_stock_location', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_zone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_location_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['service_zone_id', 'stock_location_id'], 'service_zone_stock_location_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_zone_stock_location');
    }
};
