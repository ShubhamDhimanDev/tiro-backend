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
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tyre_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_location_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('qty_on_hand')->default(0);
            $table->unsignedInteger('qty_reserved')->default(0);
            $table->unsignedInteger('reorder_point')->default(0);
            $table->timestamps();

            $table->unique(['tyre_variant_id', 'stock_location_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
