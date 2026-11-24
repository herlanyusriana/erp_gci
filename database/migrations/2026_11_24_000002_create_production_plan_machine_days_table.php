<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_plan_machine_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_plan_id')->constrained('production_plans')->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained('machines')->cascadeOnDelete();
            $table->boolean('initial_qdc')->default(false);
            $table->timestamps();

            $table->unique(['production_plan_id', 'machine_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_plan_machine_days');
    }
};
