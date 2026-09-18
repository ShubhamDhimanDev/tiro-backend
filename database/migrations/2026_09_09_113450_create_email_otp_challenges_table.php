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
        Schema::create('email_otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('code_hash');
            $table->string('purpose');
            $table->string('pending_password_hash')->nullable();
            $table->timestamp('expires_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_otp_challenges');
    }
};
