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
        Schema::create('popular_sizes', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('profile');
            $table->unsignedSmallInteger('rim_diameter');
            $table->integer('sort_order')->default(0);
            $table->string('status')->default(Status::Active->value);
            $table->timestamps();

            $table->unique(['width', 'profile', 'rim_diameter']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('popular_sizes');
    }
};
