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
        Schema::create('vans', function (Blueprint $table) {
            $table->id();
            $table->string('rego')->unique();
            $table->string('name');
            $table->foreignId('home_stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->boolean('has_alignment_equipment')->default(false);
            // Admin-editable per van, not a global constant — see
            // docs/architecture/01-data-model.md's Fleet & capacity section.
            $table->unsignedTinyInteger('max_jobs_per_day')->default(8);
            $table->string('status')->default(Status::Active->value);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vans');
    }
};
