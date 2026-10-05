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
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('suburb_id')->constrained()->restrictOnDelete();
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('postcode', 4);
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->text('access_instructions')->nullable();
            // AddressType [fitting|billing] — billing is reserved for a
            // future separate-billing-address feature, not built this
            // phase. See docs/architecture/01-data-model.md's `Address`
            // section.
            $table->string('type');
            $table->timestamps();

            // Account address book (Phase 7's "saved addresses").
            $table->index('customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
