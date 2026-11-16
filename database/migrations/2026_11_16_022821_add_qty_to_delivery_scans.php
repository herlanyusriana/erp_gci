<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Qty yang dicatat operator saat memindai label FG di APK.
        Schema::table('delivery_scans', function (Blueprint $table) {
            $table->decimal('qty', 20, 4)->nullable()->after('part_stock_id');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_scans', function (Blueprint $table) {
            $table->dropColumn('qty');
        });
    }
};
