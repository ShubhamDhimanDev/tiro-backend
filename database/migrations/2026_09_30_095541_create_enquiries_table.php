<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 16)->unique();
            $table->string('type', 20);
            $table->string('status', 20)->default('new');
            $table->string('name');
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->text('message')->nullable();
            $table->string('tyre_size', 40)->nullable();
            $table->string('rego', 12)->nullable();
            $table->string('rego_state', 3)->nullable();
            $table->string('suburb')->nullable();
            $table->string('postcode', 4)->nullable();
            $table->string('company')->nullable();
            $table->unsignedSmallInteger('fleet_size')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiries');
    }
};
