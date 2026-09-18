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
        Schema::create('service_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('state_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->decimal('origin_lat', 10, 7)->nullable();
            $table->decimal('origin_lng', 10, 7)->nullable();
            $table->decimal('radius_km', 6, 2)->nullable();
            $table->json('operating_hours')->nullable();
            $table->integer('priority')->default(0);
            $table->string('status')->default(Status::Active->value);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_zones');
    }
};
