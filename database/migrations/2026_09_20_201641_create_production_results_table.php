<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hasil produksi per step (parent_part) — realisasi dari routing WO.
        Schema::create('production_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('parent_part_id')->constrained('parts')->cascadeOnDelete();
            $table->foreignId('process_id')->nullable()->constrained('processes')->nullOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained('machines')->nullOnDelete();
            $table->date('result_date');
            $table->string('shift')->nullable();
            $table->decimal('qty_good', 20, 4)->default(0);
            $table->decimal('qty_reject', 20, 4)->default(0);
            $table->string('uom')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['work_order_id', 'parent_part_id']);
            $table->index('result_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_results');
    }
};
