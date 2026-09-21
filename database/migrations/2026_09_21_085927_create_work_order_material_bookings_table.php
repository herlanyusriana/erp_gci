<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Booking material WO: dibuat saat release (stok belum berkurang),
        // dikonsumsi saat Production Result, dilepas saat WO cancel.
        Schema::create('work_order_material_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('work_order_item_id')->constrained('work_order_items')->cascadeOnDelete();
            $table->foreignId('part_id')->nullable()->constrained('parts')->nullOnDelete();
            $table->foreignId('part_stock_id')->nullable()->constrained('part_stocks')->nullOnDelete();
            $table->string('tag')->nullable();
            $table->decimal('qty', 20, 4)->default(0);
            $table->string('uom', 20)->nullable();
            $table->string('status', 20)->default('booked'); // booked | consumed | released
            $table->timestamp('booked_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['work_order_id', 'status']);
            $table->index(['part_id', 'status']);
            $table->index(['part_stock_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_material_bookings');
    }
};
