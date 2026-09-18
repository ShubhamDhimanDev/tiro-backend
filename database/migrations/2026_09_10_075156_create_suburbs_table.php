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
        Schema::create('suburbs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('state_id')->constrained()->restrictOnDelete();
            $table->string('postcode', 4);
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->timestamps();

            $table->unique(['name', 'state_id', 'postcode']);
            $table->index('postcode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suburbs');
    }
};
