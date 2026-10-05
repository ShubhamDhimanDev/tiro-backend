<?php

use App\Enums\PageStatus;
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
        Schema::create('content_pages', function (Blueprint $table) {
            $table->id();

            // One type-discriminated entity, not five separate tables —
            // see the Phase 6 task brief. `(type, slug)` is unique, NOT
            // globally unique: different types are separate URL namespaces
            // on frontend/, so a slug collision across two different types
            // shouldn't be DB-blocked.
            $table->string('type');
            $table->string('title');
            $table->string('slug');
            $table->text('excerpt')->nullable();
            $table->longText('body');
            $table->string('featured_image_path')->nullable();

            // SEO overrides — frontend falls back to title/excerpt/
            // featured_image_path respectively when these are null, see the
            // model's docblock.
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('og_image_path')->nullable();

            // Free-text admin-curated grouping (e.g. "maintenance-tips"),
            // deliberately NOT a hardcoded enum like `type` — admin curates
            // from existing distinct values.
            $table->string('category')->nullable();

            $table->string('status')->default(PageStatus::Draft->value);
            $table->timestamp('published_at')->nullable();

            // Optional linkage only — location pages are primarily static
            // authored content; this just enables an optional live-data
            // widget when set, never a requirement. Same posture for
            // promotion_id below.
            $table->foreignId('service_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('promotion_id')->nullable()->constrained()->nullOnDelete();

            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['type', 'slug']);

            // The exact WHERE clause the public read API uses — see the
            // visibility rule in the model's docblock.
            $table->index(['type', 'status', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_pages');
    }
};
