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
        Schema::create('tyre_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('category');
            $table->string('tyre_type');
            $table->string('construction');
            $table->boolean('run_flat')->default(false);
            $table->text('description')->nullable();
            $table->text('warranty_text')->nullable();
            $table->unsignedInteger('warranty_km')->nullable();
            $table->json('service_inclusions')->nullable();
            $table->date('released_at')->nullable();
            $table->json('images')->nullable();
            $table->string('status')->default(Status::Active->value);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tyre_models');
    }
};
