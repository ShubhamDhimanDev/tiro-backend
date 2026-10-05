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
        Schema::create('price_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_zone_id')->constrained()->cascadeOnDelete();

            // Reuses App\Enums\CancellationFeeType's shape (flat|percent) —
            // do not declare a near-identical second enum, per
            // docs/architecture/01-data-model.md's PriceRule section.
            $table->string('fee_type');
            $table->integer('fee_amount')->nullable();
            $table->unsignedTinyInteger('fee_percent')->nullable();
            $table->string('status')->default(Status::Active->value);
            $table->timestamps();

            $table->index('service_zone_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_rules');
    }
};
