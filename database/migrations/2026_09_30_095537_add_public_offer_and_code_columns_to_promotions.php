<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6a: public offers + typed promo codes on the existing promotions
     * engine (no second engine). A promotion with a non-null `code` is NOT
     * auto-applied — it only applies when the customer supplies that code.
     * `is_public` gates the public `GET /api/v1/offers` listing.
     */
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->unique()->after('name');
            $table->string('slug')->nullable()->unique()->after('code');
            $table->string('title')->nullable()->after('slug');
            $table->text('summary')->nullable()->after('title');
            $table->string('badge_text', 60)->nullable()->after('summary');
            $table->string('discount_description')->nullable()->after('badge_text');
            $table->text('terms')->nullable()->after('discount_description');
            $table->string('image_path')->nullable()->after('terms');
            $table->boolean('is_public')->default(false)->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropUnique(['slug']);
            $table->dropColumn(['code', 'slug', 'title', 'summary', 'badge_text', 'discount_description', 'terms', 'image_path', 'is_public']);
        });
    }
};
