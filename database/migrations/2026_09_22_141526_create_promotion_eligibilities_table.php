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
        Schema::create('promotion_eligibilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->string('scope');

            // "Polymorphic-by-convention" per docs/architecture/01-data-model.md
            // — but NOT a plain integer FK for every `scope` value: `brand`/
            // `tyre_model`/`tyre_variant` store that table's numeric id (as a
            // string), while `category` has no lookup table to point an FK
            // at (App\Enums\TyreCategory is a fixed string enum, never
            // seeded as rows — see docs/architecture/01-data-model.md's
            // Catalogue section) and stores the enum's raw string value
            // instead (e.g. "suv"), mirroring how `DurationRule.key` already
            // stores TyreCategory's values as plain strings rather than
            // inventing a numeric category-id mapping nothing else in this
            // schema uses. A string column accommodates both without a
            // fabricated numeric mapping. Flagged as a genuine data-model
            // gap resolved here — see the Phase 5 handback.
            $table->string('scope_id');

            $table->foreignId('service_zone_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            // The eligibility-matching query's shape: "does any row for this
            // promotion match this unit's scope+scope_id (and zone)".
            $table->index(['promotion_id', 'scope', 'scope_id']);
            $table->index('service_zone_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotion_eligibilities');
    }
};
