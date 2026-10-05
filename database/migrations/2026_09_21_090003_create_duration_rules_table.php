<?php

use App\Enums\Status;
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
        Schema::create('duration_rules', function (Blueprint $table) {
            $table->id();
            $table->string('applies_to');
            $table->string('key');
            $table->unsignedSmallInteger('minutes');
            $table->string('status')->default(Status::Active->value);
            $table->timestamps();

            $table->unique(['applies_to', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('duration_rules');
    }
};
