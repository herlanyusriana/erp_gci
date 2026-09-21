<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cycle time / tact time: satu nilai per kombinasi mesin × part (detik per pcs).
        Schema::create('machine_cycle_times', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained('machines')->cascadeOnDelete();
            $table->foreignId('part_id')->constrained('parts')->cascadeOnDelete();
            $table->decimal('cycle_time_seconds', 12, 4);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['machine_id', 'part_id']);
            $table->index(['part_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_cycle_times');
    }
};
