<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Qty yang benar-benar dipotong dari stok untuk tag ini; sumber tunggal
        // saat pengembalian ke lokasi asal.
        Schema::table('delivery_scans', function (Blueprint $table) {
            $table->decimal('deducted_qty', 20, 4)->nullable()->after('qty');
        });

        // Data lama: dokumen yang sudah pernah dipindah dianggap memotong
        // sebesar qty scan-nya, supaya pengembalian ke lokasi asal tetap akurat.
        DB::table('delivery_scans')
            ->where('scan_type', 'tag')
            ->whereNotNull('part_stock_id')
            ->whereIn('delivery_id', DB::table('deliveries')->whereNotNull('stock_deducted_at')->select('id'))
            ->update(['deducted_qty' => DB::raw('qty')]);
    }

    public function down(): void
    {
        Schema::table('delivery_scans', function (Blueprint $table) {
            $table->dropColumn('deducted_qty');
        });
    }
};
