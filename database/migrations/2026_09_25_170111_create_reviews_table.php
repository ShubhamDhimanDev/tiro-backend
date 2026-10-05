<?php

use App\Enums\ReviewSource;
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
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();

            // Single source today (`ReviewSource::Google` only, see that
            // enum's docblock). `(source, external_id)` — not `external_id`
            // alone — is the sync upsert key, so a future second source
            // can't collide with Google's id space.
            $table->string('source')->default(ReviewSource::Google->value);
            $table->string('external_id');

            $table->unsignedTinyInteger('rating');
            $table->string('author_name');
            $table->string('author_photo_url')->nullable();

            // Nullable: Google allows a star-only rating with no comment.
            $table->text('body')->nullable();

            // Deep link back to the review on Google — Google's own
            // attribution expectation, also the storefront's "see original"
            // link.
            $table->string('review_url')->nullable();

            // The business's own reply — already present in the same
            // `reviews.list` payload, no separate fetch. See the model's
            // docblock.
            $table->text('reply_body')->nullable();
            $table->timestamp('replied_at')->nullable();

            // Stays null for every row under the current one-GBP-listing
            // default (Tiro is a mobile service, not a chain of
            // storefronts) — schema-ready for a future per-listing split,
            // not built on assuming one. See the model's docblock.
            $table->foreignId('location_id')->nullable()->constrained('service_zones')->nullOnDelete();

            // Admin moderation override — the sync upsert must NEVER
            // overwrite this on an existing row (see the model's docblock);
            // defaults false only on first insert.
            $table->boolean('is_hidden')->default(false);

            // The review's own creation date on Google, not sync time.
            $table->timestamp('published_at');

            // Last successful sync write.
            $table->timestamp('cached_at');

            $table->timestamps();

            $table->unique(['source', 'external_id']);

            // The public listing's exact WHERE/ORDER BY.
            $table->index(['is_hidden', 'published_at']);
            $table->index('location_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
