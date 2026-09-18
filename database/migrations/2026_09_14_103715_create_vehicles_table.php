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
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            // Explicit, shorter-than-default lengths on the four string
            // columns in the composite unique index below — utf8mb4's 4
            // bytes/char means four unbounded VARCHAR(255) columns would
            // exceed InnoDB's 3072-byte max key length.
            $table->string('make', 100);
            $table->string('model', 100);
            $table->string('series', 100)->nullable();
            $table->string('body_type', 50)->nullable();
            $table->unsignedSmallInteger('year_from');
            $table->unsignedSmallInteger('year_to');
            $table->string('slug')->unique();
            $table->string('status')->default(Status::Active->value);
            $table->timestamps();

            // NULL is distinct-per-row in a MySQL unique index, so two rows
            // that both leave `series`/`body_type` null won't be caught by
            // this constraint — acceptable, see docs/architecture/01-data-model.md
            // ("the importer/admin form is the practical de-dupe backstop,
            // not the DB alone").
            $table->unique(
                ['make', 'model', 'series', 'body_type', 'year_from', 'year_to'],
                'vehicles_generation_unique',
            );
            // Covers the manual picker's make -> model -> year cascade;
            // `make` alone is a usable prefix of this same index.
            $table->index(['make', 'model', 'year_from', 'year_to'], 'vehicles_cascade_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
