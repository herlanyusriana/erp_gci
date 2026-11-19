<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mata uang PO sebelumnya tidak disimpan: halaman cetak menebaknya dari
     * `suppliers.source` (import -> USD, sisanya IDR). Tebakan itu tidak bisa
     * diubah saat supplier menagih mata uang lain, dan tidak terlihat di form.
     *
     * Kolom ini menyimpan nilai final per PO. Baris lama diisi dengan aturan
     * yang sama seperti tebakan cetak, jadi dokumen lama tetap tercetak sama.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('currency', 10)->nullable()->after('status');
        });

        DB::statement(<<<'SQL'
            UPDATE purchase_orders AS po
            SET currency = CASE WHEN s.source = 'import' THEN 'USD' ELSE 'IDR' END
            FROM suppliers AS s
            WHERE s.id = po.supplier_id
        SQL);

        // `supplier_id` boleh kosong, dan baris seperti itu tidak ikut UPDATE di
        // atas. Nilainya disamakan dengan tebakan cetak lama (IDR) supaya tidak
        // ada PO yang tersimpan tanpa mata uang.
        DB::statement("UPDATE purchase_orders SET currency = 'IDR' WHERE currency IS NULL");
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
