<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Alokasi material per item WO: satu item bisa dipenuhi dari beberapa
        // part (main material BOM sebagai acuan + substitute pemegang stok).
        Schema::create('work_order_item_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_item_id')->constrained('work_order_items')->cascadeOnDelete();
            $table->foreignId('part_id')->constrained('parts')->cascadeOnDelete();
            $table->decimal('qty', 20, 4)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['work_order_item_id', 'part_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_item_allocations');
    }
};
