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
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();

            // Not in docs/architecture/01-data-model.md's field sketch, but
            // required by 02-api-contract.md's `cart/calculate` response
            // shape (`applied_promotion.name`/`applied_promotions[].name`,
            // e.g. "4 for 3 — Select Bridgestone") — there is no other
            // source for a customer-facing display name. Genuine gap in the
            // data model's field list, added here; see the Phase 5 handback.
            $table->string('name');

            // No `code` column — decision #18
            // (docs/architecture/06-open-decisions.md), auto-applied only,
            // no customer-typed coupon entry this phase.
            $table->string('type');
            $table->integer('value');
            $table->date('starts_at');
            $table->date('ends_at');
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->unsignedInteger('stock_limit')->nullable();
            $table->boolean('stackable')->default(false);
            $table->string('status')->default(Status::Draft->value);
            $table->timestamps();

            // The evaluation algorithm's step 1 candidate query filters on
            // exactly this triple (status=active AND today between
            // starts_at/ends_at) for every cart/calculate and booking-creation
            // call — see docs/architecture/05-promotions-pricing.md.
            $table->index(['status', 'starts_at', 'ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
