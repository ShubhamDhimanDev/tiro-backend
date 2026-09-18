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
        Schema::create('tyre_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tyre_model_id')->constrained()->restrictOnDelete();
            $table->string('sku')->unique();
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('profile');
            $table->unsignedSmallInteger('rim_diameter');
            $table->string('load_index');
            $table->string('speed_rating');
            $table->string('sidewall');
            $table->string('ean')->nullable();
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->unsignedInteger('base_price');
            $table->string('status')->default(Status::Active->value);
            $table->timestamps();

            $table->unique(
                ['tyre_model_id', 'width', 'profile', 'rim_diameter', 'load_index', 'speed_rating'],
                'tyre_variants_spec_unique',
            );
            $table->index(['width', 'profile', 'rim_diameter'], 'tyre_variants_size_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tyre_variants');
    }
};
