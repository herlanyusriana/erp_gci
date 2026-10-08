<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_material_receipts', function (Blueprint $table) {
            $table->decimal('qty_consumed', 26, 10)->default(0)->change();
        });
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->decimal('qty_consumed', 26, 10)->default(0)->change();
        });
        Schema::table('work_order_consumptions', function (Blueprint $table) {
            $table->decimal('qty', 26, 10)->default(0)->change();
        });
        Schema::table('part_stocks', function (Blueprint $table) {
            $table->decimal('qty', 26, 10)->default(0)->change();
        });
    }

    public function down(): void
    {
        throw new LogicException('Quantity precision cannot be narrowed safely. Use a forward migration after reconciliation.');
    }
};
