<?php

use App\Models\Address;
use App\Services\Customers\CustomerVehicleService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase 7 account UI additions — `label` is a customer-editable
     * nickname, `is_default` is write-layer-enforced (single default per
     * customer, see {@see CustomerVehicleService})
     * rather than a DB constraint, matching {@see Address}'s
     * identical addition in the sibling migration.
     */
    public function up(): void
    {
        Schema::table('customer_vehicles', function (Blueprint $table) {
            $table->string('label')->nullable()->after('customer_id');
            $table->boolean('is_default')->default(false)->after('saved_fitment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_vehicles', function (Blueprint $table) {
            $table->dropColumn(['label', 'is_default']);
        });
    }
};
