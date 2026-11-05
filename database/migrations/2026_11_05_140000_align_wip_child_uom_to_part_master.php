<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Selaraskan uom_rm pada bom_items dan work_order_items yang child-nya bertipe WIP.
     * Part WIP selalu diproduksi dalam satuan master part-nya (umumnya PCS).
     * Mencegah kegagalan konsumsi WIP akibat uom_rm tercatat sebagai 'SHEET' dari data impor lama.
     */
    public function up(): void
    {
        DB::statement("
            UPDATE bom_items bi
            SET uom_rm = u.code
            FROM parts p
            JOIN part_types pt ON pt.id = p.part_type_id
            JOIN uoms u ON u.id = p.uom_id
            WHERE bi.child_part_id = p.id
              AND UPPER(pt.code) = 'WIP'
              AND UPPER(COALESCE(bi.uom_rm, '')) != UPPER(u.code)
        ");

        DB::statement("
            UPDATE work_order_items woi
            SET uom_rm = u.code
            FROM parts p
            JOIN part_types pt ON pt.id = p.part_type_id
            JOIN uoms u ON u.id = p.uom_id
            WHERE woi.child_part_id = p.id
              AND UPPER(pt.code) = 'WIP'
              AND UPPER(COALESCE(woi.uom_rm, '')) != UPPER(u.code)
        ");
    }

    public function down(): void
    {
        // Tidak perlu di-rollback karena perbaikan data ke UOM master part yang benar.
    }
};
