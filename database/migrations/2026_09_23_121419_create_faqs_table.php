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
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->text('question');
            $table->longText('answer');

            // Same free-text posture as content_pages.category. "pdp" is a
            // reserved category value the PDP's shared/global FAQ block
            // filters on (GET /api/v1/content/faqs?category=pdp).
            $table->string('category')->nullable();

            // null = global/shared FAQ. Non-null scopes this FAQ to one
            // specific page's own FAQ block (e.g. a location page's local
            // FAQs).
            $table->foreignId('content_page_id')->nullable()->constrained()->cascadeOnDelete();

            $table->integer('sort_order')->default(0);

            // Reuses ContentPage's own PageStatus enum, not a second
            // near-identical one — see the Phase 6 task brief.
            $table->string('status')->default(PageStatus::Draft->value);
            $table->timestamps();

            $table->index(['category', 'status', 'sort_order']);
            $table->index(['content_page_id', 'status', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
