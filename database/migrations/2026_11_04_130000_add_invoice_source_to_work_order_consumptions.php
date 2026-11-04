<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_consumptions', function (Blueprint $table) {
            $table->string('invoice_no')->nullable()->after('uom');
            $table->string('supplier_name')->nullable()->after('invoice_no');
            $table->string('source_tag')->nullable()->after('supplier_name');
        });

        // Isi balik data lama: receipt penerimaan sudah menyimpan invoice/supplier,
        // jadi konsumsi yang sudah ada masih bisa dilacak ke asalnya.
        DB::statement('
            UPDATE work_order_consumptions c
            SET invoice_no = r.invoice,
                supplier_name = r.supplier,
                source_tag = r.tag
            FROM production_material_receipts r
            WHERE c.production_material_receipt_id = r.id
        ');
    }

    public function down(): void
    {
        Schema::table('work_order_consumptions', function (Blueprint $table) {
            $table->dropColumn(['invoice_no', 'supplier_name', 'source_tag']);
        });
    }
};
