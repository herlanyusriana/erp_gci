<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────
        // WORK ORDERS (header)
        // ──────────────────────────────────────────────
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('wo_no')->unique();
            $table->foreignId('part_id')->constrained('parts')->cascadeOnDelete(); // FG
            $table->decimal('qty', 20, 4)->default(0); // qty FG yang dipesan
            $table->string('status')->default('planned'); // planned | in_progress | completed | cancelled
            $table->date('planned_date')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['part_id']);
            $table->index(['status']);
        });

        // ──────────────────────────────────────────────
        // WORK ORDER ITEMS (snapshot routing dari bom_items, beku saat create)
        // ──────────────────────────────────────────────
        Schema::create('work_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->unsignedInteger('sequence')->nullable();
            $table->foreignId('process_id')->nullable()->constrained('processes')->nullOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained('machines')->nullOnDelete();
            $table->foreignId('parent_part_id')->nullable()->constrained('parts')->nullOnDelete();
            $table->string('parent_part_name')->nullable();
            $table->decimal('parent_qty', 18, 6)->nullable();
            $table->string('parent_uom')->nullable();
            $table->foreignId('child_part_id')->nullable()->constrained('parts')->nullOnDelete();
            $table->string('child_part_name')->nullable();
            $table->string('size')->nullable();
            $table->decimal('child_qty', 18, 6)->nullable();
            $table->string('uom_rm')->nullable();
            $table->string('special_code')->nullable();
            $table->string('source')->nullable();
            // kebutuhan = child_qty × qty WO; dipenuhi saat release
            $table->decimal('qty_required', 20, 4)->default(0);
            $table->decimal('qty_consumed', 20, 4)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['work_order_id', 'sequence']);
        });

        // ──────────────────────────────────────────────
        // WORK ORDER CONSUMPTIONS (jejak alokasi FIFO saat release)
        // ──────────────────────────────────────────────
        Schema::create('work_order_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('work_order_item_id')->constrained('work_order_items')->cascadeOnDelete();
            $table->unsignedBigInteger('part_stock_id')->nullable()->index(); // baris bisa habis-terhapus; tidak pakai FK keras agar jejak awet
            $table->foreignId('part_id')->nullable()->constrained('parts')->nullOnDelete();
            $table->decimal('qty', 20, 4)->default(0);
            $table->string('uom', 20)->nullable();
            $table->timestamps();
            $table->index(['work_order_id']);
            $table->index(['part_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_consumptions');
        Schema::dropIfExists('work_order_items');
        Schema::dropIfExists('work_orders');
    }
};