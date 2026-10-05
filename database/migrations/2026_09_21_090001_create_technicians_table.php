<?php

use App\Enums\Status;
use App\Enums\TechnicianEmploymentType;
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
        Schema::create('technicians', function (Blueprint $table) {
            $table->id();
            // Nullable by design, not a gap — a roster record can exist
            // before app access is provisioned, or for a contractor who
            // never needs panel access at all. See
            // docs/architecture/07-admin-auth-permissions.md §5.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('employment_type')->default(TechnicianEmploymentType::Employee->value);
            $table->json('certifications')->nullable();
            $table->string('status')->default(Status::Active->value);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('technicians');
    }
};
