<?php

use App\Listeners\LogNotificationDelivery;
use App\Models\NotificationLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One immutable row per channel per notification send — see
     * {@see LogNotificationDelivery} (the only writer) and
     * {@see NotificationLog}'s docblock. Deliberately stores no
     * message subject/body/order total/address — metadata only, the locked
     * structural answer to Phase 7's PII-leakage security review.
     *
     * `created_at` only (no `updated_at`) via `useCurrent()` rather than the
     * `timestamps()` macro — a delivery-attempt fact is never updated after
     * the fact, only ever superseded by a new row (e.g. a reminder retried
     * next run gets its own row, not a mutated one).
     */
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();

            // Polymorphic recipient — always `Customer` for every event this
            // phase, kept polymorphic so a future staff-facing notification
            // doesn't need a second table.
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');

            // Dotted-namespace event type, e.g. `booking.confirmed` — see
            // App\Notifications\* for the exhaustive list this phase.
            $table->string('type');
            $table->string('channel');
            $table->string('status');

            // Actual email/E.164 phone used for this attempt — not a live
            // read of `Customer.email`/`Customer.mobile`, which may change
            // after the fact.
            $table->string('recipient');
            $table->string('provider_message_id')->nullable();

            // Provider FAILURE REASON ONLY — never message content, see
            // this migration's class docblock.
            $table->text('error_message')->nullable();

            // What this notification was about, e.g. `Booking` — nullable
            // since not every future notification type need relate to one
            // row.
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['notifiable_type', 'notifiable_id']);
            $table->index(['related_type', 'related_id']);
            $table->index(['type', 'channel', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
