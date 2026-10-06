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
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('original_name');
            // Final WebP + thumbnail on the public disk; null until processed.
            $table->string('path')->nullable();
            $table->string('thumb_path')->nullable();
            // Raw upload on the private disk, kept only until converted.
            $table->string('incoming_path')->nullable();
            // Set when the image was fetched from another server. The hash
            // (not the URL) is unique: URLs can exceed MySQL's index limit.
            $table->text('source_url')->nullable();
            $table->char('source_hash', 40)->nullable()->unique();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('error')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
