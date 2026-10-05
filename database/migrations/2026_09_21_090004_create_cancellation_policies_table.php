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
        Schema::create('cancellation_policies', function (Blueprint $table) {
            $table->id();
            // Nullable = global default, applied when no zone-specific row
            // exists — see docs/architecture/01-data-model.md.
            $table->foreignId('service_zone_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('notice_hours');
            $table->string('fee_type');
            $table->integer('fee_amount')->nullable();
            $table->unsignedTinyInteger('fee_percent')->nullable();
            $table->string('status')->default(Status::Active->value);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cancellation_policies');
    }
};
