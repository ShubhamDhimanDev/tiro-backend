<?php

use App\Enums\PaymentType;
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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default(PaymentType::Charge->value);
            $table->string('gateway');
            $table->string('method');
            $table->string('status');
            // Always positive — sign/direction is expressed by `type`
            // (charge|refund), never by a negative amount. See
            // docs/architecture/01-data-model.md's `Payment` section.
            $table->integer('amount');
            // The Stripe PaymentIntent id for type=charge rows, the Stripe
            // Refund id for type=refund rows. Unique — the concrete
            // mechanism that makes Stripe webhook redelivery safe at the
            // data layer, defense-in-depth alongside the cache-based dedup.
            $table->string('gateway_reference')->unique();
            $table->json('raw_response')->nullable();
            // Populated for type=refund rows only — a server-initiated
            // action still needs one, see the refund-flow section of
            // docs/architecture/02-api-contract.md.
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();

            $table->index('order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
