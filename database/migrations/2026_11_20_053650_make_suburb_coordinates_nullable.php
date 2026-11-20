<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suburbs bulk-imported from a name/postcode/state sheet have no
     * centroid yet. Radius-zone matching skips them until coordinates are
     * filled in; suburb-list zone matching does not need coordinates.
     */
    public function up(): void
    {
        Schema::table('suburbs', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->change();
            $table->decimal('lng', 10, 7)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations. Rows without coordinates cannot satisfy the
     * NOT NULL constraint, so they are set to 0 first.
     */
    public function down(): void
    {
        DB::table('suburbs')->whereNull('lat')->update(['lat' => 0]);
        DB::table('suburbs')->whereNull('lng')->update(['lng' => 0]);

        Schema::table('suburbs', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable(false)->change();
            $table->decimal('lng', 10, 7)->nullable(false)->change();
        });
    }
};
