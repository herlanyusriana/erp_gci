<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Alur final: picking (web) -> pindah ke prep (APK) -> OQC (APK)
        // -> isi PEN/AJU (web) -> submit outgoing (stok berkurang) -> cetak dokumen.
        // Tahap `loaded` dihapus karena label hanya dicetak, bukan gerbang status.
        DB::table('deliveries')->where('status', 'loaded')->update([
            'status' => 'qc_passed',
            'loaded_at' => null,
        ]);

        Schema::table('deliveries', function (Blueprint $table) {
            $table->string('pen_no')->nullable()->after('invoice_no');
            $table->string('aju_no')->nullable()->after('pen_no');
        });

        Schema::table('delivery_items', function (Blueprint $table) {
            // Urutan keberangkatan FG pada tanggal pengiriman itu.
            $table->unsignedInteger('sequence')->default(1)->after('delivery_id');
            // Dicentang operator saat FG benar-benar sudah diambil.
            $table->boolean('is_picked')->default(false)->after('qty_unit');
        });

        Schema::table('delivery_scans', function (Blueprint $table) {
            // OQC per troli: ok / ng. Null berarti belum diperiksa.
            $table->string('qc_status', 10)->nullable()->after('part_stock_id');
            $table->string('qc_notes')->nullable()->after('qc_status');
            $table->timestamp('qc_checked_at')->nullable()->after('qc_notes');
            $table->unsignedBigInteger('qc_checked_by')->nullable()->after('qc_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_scans', function (Blueprint $table) {
            $table->dropColumn(['qc_status', 'qc_notes', 'qc_checked_at', 'qc_checked_by']);
        });

        Schema::table('delivery_items', function (Blueprint $table) {
            $table->dropColumn(['sequence', 'is_picked']);
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['pen_no', 'aju_no']);
        });
    }
};
