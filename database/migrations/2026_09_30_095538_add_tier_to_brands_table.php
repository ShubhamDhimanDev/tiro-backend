<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6a: brand-level positioning tier (premium|mid|budget) for the
     * redesigned listing. Nullable: an unclassified brand simply has no tier.
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('tier', 16)->nullable()->after('country_of_origin');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('tier');
        });
    }
};
