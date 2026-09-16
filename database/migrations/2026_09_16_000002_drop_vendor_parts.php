<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropForeign(['vendor_part_id']);
            $table->dropIndex(['vendor_part_id']);
            $table->dropColumn('vendor_part_id');
        });

        Schema::table('incoming_arrival_items', function (Blueprint $table) {
            $table->dropForeign(['vendor_part_id']);
            $table->dropIndex(['vendor_part_id']);
            $table->dropColumn('vendor_part_id');
        });

        Schema::dropIfExists('vendor_parts');
    }

    public function down(): void
    {
        Schema::create('vendor_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->nullable()->constrained('parts')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('vendor_part_no');
            $table->string('vendor_part_name')->nullable();
            $table->decimal('price', 20, 4)->nullable();
            $table->string('currency', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['supplier_id', 'vendor_part_no']);
            $table->index(['part_id']);
            $table->index(['vendor_part_no']);
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('vendor_part_id')->nullable()->constrained('vendor_parts')->nullOnDelete();
            $table->index(['vendor_part_id']);
        });

        Schema::table('incoming_arrival_items', function (Blueprint $table) {
            $table->foreignId('vendor_part_id')->nullable()->constrained('vendor_parts')->nullOnDelete();
            $table->index(['vendor_part_id']);
        });
    }
};
