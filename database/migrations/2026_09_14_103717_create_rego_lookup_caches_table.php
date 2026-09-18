<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Schema-only this phase — no read/write code path exists until a
     * rego-lookup vendor is contracted (open decision #3). See
     * docs/architecture/01-data-model.md's RegoLookupCache section.
     */
    public function up(): void
    {
        Schema::create('rego_lookup_caches', function (Blueprint $table) {
            $table->id();
            $table->string('rego');
            // AU rego plates are state-issued and not unique nationally —
            // the cache key is always the (rego, state) pair, never `rego`
            // alone.
            $table->string('state');
            $table->json('raw_response');
            $table->foreignId('resolved_vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->timestamp('looked_up_at');
            // No fixed enum cast — see docs/architecture/01-data-model.md;
            // deferred alongside the rest of the vendor-integration build.
            $table->string('status')->nullable();
            $table->timestamps();

            // `rego` is the leading column of this composite unique index,
            // so it already serves as the "rego (indexed)" lookup index
            // documented alongside it — no separate single-column index
            // needed.
            $table->unique(['rego', 'state']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rego_lookup_caches');
    }
};
