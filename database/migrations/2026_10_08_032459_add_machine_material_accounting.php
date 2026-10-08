<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_material_receipts', function (Blueprint $table) {
            $table->string('transfer_status')->default('legacy_unverified');
            $table->unsignedBigInteger('work_order_id')->nullable();
            $table->unsignedBigInteger('work_order_item_id')->nullable();
            $table->unsignedBigInteger('part_stock_id')->nullable();
            $table->decimal('qty', 20, 4)->nullable();
            $table->decimal('qty_consumed', 20, 4)->default(0);
            $table->string('uom', 20)->nullable();
            $table->string('invoice')->nullable();
            $table->string('supplier')->nullable();
            $table->index(['work_order_item_id', 'machine_id', 'transfer_status'], 'receipt_machine_balance_index');
        });
        Schema::table('work_order_material_bookings', function (Blueprint $table) {
            $table->timestamp('transferred_at')->nullable();
        });
        Schema::table('production_results', function (Blueprint $table) {
            $table->unsignedBigInteger('output_part_stock_id')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->softDeletes();
        });
        Schema::table('work_order_consumptions', function (Blueprint $table) {
            $table->unsignedBigInteger('production_result_id')->nullable()->index();
            $table->unsignedBigInteger('production_material_receipt_id')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('work_order_consumptions', function (Blueprint $table) {
            $table->dropIndex(['production_result_id']);
            $table->dropColumn(['production_result_id', 'production_material_receipt_id', 'reversed_by', 'deleted_at']);
        });
        Schema::table('production_results', function (Blueprint $table) {
            $table->dropColumn(['output_part_stock_id', 'reversed_by', 'deleted_at']);
        });
        Schema::table('work_order_material_bookings', function (Blueprint $table) {
            $table->dropColumn('transferred_at');
        });
        Schema::table('production_material_receipts', function (Blueprint $table) {
            $table->dropIndex('receipt_machine_balance_index');
            $table->dropColumn(['transfer_status', 'work_order_id', 'work_order_item_id', 'part_stock_id', 'qty', 'qty_consumed', 'uom', 'invoice', 'supplier']);
        });
    }
};
