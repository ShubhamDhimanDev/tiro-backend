<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6a: the public state > city > suburb hierarchy. There was no
     * "city" concept, so a service zone declares which city it belongs to;
     * several zones may share one city (e.g. "Melbourne Metro" and
     * "Melbourne CBD Express" are both Melbourne). A zone with a null
     * `city_name` is not listed publicly.
     */
    public function up(): void
    {
        Schema::table('service_zones', function (Blueprint $table) {
            $table->string('city_name')->nullable()->after('name');
            $table->string('city_slug')->nullable()->after('city_name');
            $table->index(['state_id', 'city_slug']);
        });
    }

    public function down(): void
    {
        Schema::table('service_zones', function (Blueprint $table) {
            $table->dropIndex(['state_id', 'city_slug']);
            $table->dropColumn(['city_name', 'city_slug']);
        });
    }
};
