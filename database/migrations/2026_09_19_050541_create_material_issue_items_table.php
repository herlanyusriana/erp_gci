<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_issue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_issue_id')->constrained('material_issues')->cascadeOnDelete();
            $table->foreignId('work_order_item_id')->constrained('work_order_items')->cascadeOnDelete();
            $table->foreignId('part_id')->constrained('parts')->cascadeOnDelete();
            // Tanpa FK keras: baris stok bisa habis-terhapus, jejak harus awet.
            $table->unsignedBigInteger('part_stock_id')->nullable();
            $table->string('tag')->nullable();
            $table->decimal('qty', 20, 4)->default(0);
            $table->string('uom')->nullable();
            $table->decimal('price', 20, 4)->nullable();
            $table->timestamps();

            $table->index('material_issue_id');
            $table->index('work_order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_issue_items');
    }
};
