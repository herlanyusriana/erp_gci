<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────
        // PRODUCTION PLANS (papan harian; 1 plan_date = 1 plan)
        // ──────────────────────────────────────────────
        Schema::create('production_plans', function (Blueprint $table) {
            $table->id();
            $table->date('plan_date')->unique();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        // ──────────────────────────────────────────────
        // PRODUCTION PLAN ITEMS (baris WO di papan, dikelompokkan per machine)
        // ──────────────────────────────────────────────
        Schema::create('production_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_plan_id')->constrained('production_plans')->cascadeOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained('machines')->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->foreignId('fg_part_id')->nullable()->constrained('parts')->nullOnDelete();
            $table->foreignId('wip_part_id')->nullable()->constrained('parts')->nullOnDelete();
            $table->unsignedInteger('sequence')->default(0);
            $table->decimal('target_d', 20, 4)->nullable();
            $table->decimal('target_d1', 20, 4)->nullable();
            $table->decimal('target_d2', 20, 4)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['production_plan_id', 'machine_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_plan_items');
        Schema::dropIfExists('production_plans');
    }
};
