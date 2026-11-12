<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Barang jadi belum punya lokasi. Untuk sementara lokasinya adalah
        // mesin yang memproduksinya, lalu dipindah ke area pengiriman.
        Schema::table('part_stocks', function (Blueprint $table) {
            $table->string('location_code', 40)->nullable()->after('qty_unit')->index();
        });

        // Backfill: stok yang lahir dari laporan produksi berlokasi di mesin
        // pembuatnya, supaya barang jadi lama tetap punya lokasi asal.
        DB::statement(<<<'SQL'
            UPDATE part_stocks ps
            SET location_code = UPPER(m.machine_code)
            FROM production_results pr
            JOIN machines m ON m.id = pr.machine_id
            WHERE pr.output_part_stock_id = ps.id
              AND ps.location_code IS NULL
              AND m.machine_code IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('part_stocks', function (Blueprint $table) {
            $table->dropIndex(['location_code']);
            $table->dropColumn('location_code');
        });
    }
};
