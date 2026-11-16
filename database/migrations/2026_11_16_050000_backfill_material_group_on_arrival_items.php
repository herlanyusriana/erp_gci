<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `incoming_arrival_items.material_group`.
 *
 * Sebelumnya kolom ini diisi part number saat item datang dari PO, sehingga
 * invoice/ekspor historis menampilkan part number di kolom kelompok material.
 * Kelompok yang benar ada pada pemetaan substitute supplier
 * (`part_substitutes.material_group`).
 *
 * Hanya baris yang salah atau kosong yang disentuh:
 * - `material_group` kosong / null, atau
 * - `material_group` sama dengan part number milik part itu.
 *
 * Idempoten: dijalankan berulang tidak mengubah apa pun setelah bersih.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('incoming_arrival_items as i')
            ->join('incoming_arrivals as a', 'a.id', '=', 'i.arrival_id')
            ->join('parts as p', 'p.id', '=', 'i.part_id')
            ->leftJoin('part_substitutes as ps', function ($join): void {
                $join->on('ps.substitute_part_id', '=', 'i.part_id')
                    ->on('ps.supplier_id', '=', 'a.supplier_id')
                    ->where('ps.is_active', true)
                    ->whereRaw("NULLIF(TRIM(COALESCE(ps.material_group, '')), '') IS NOT NULL");
            })
            ->whereNull('i.deleted_at')
            ->whereNotNull('i.part_id')
            ->where(function ($q): void {
                $q->whereNull('i.material_group')
                    ->orWhereRaw("TRIM(i.material_group) = ''")
                    ->orWhereRaw('UPPER(TRIM(i.material_group)) = UPPER(TRIM(p.part_number))');
            })
            ->whereRaw("NULLIF(TRIM(COALESCE(ps.material_group, '')), '') IS NOT NULL")
            ->groupBy('i.id', 'ps.material_group')
            ->selectRaw('i.id AS item_id, MAX(TRIM(ps.material_group)) AS resolved_group')
            ->get();

        foreach ($rows as $row) {
            DB::table('incoming_arrival_items')
                ->where('id', $row->item_id)
                ->update(['material_group' => $row->resolved_group]);
        }
    }

    public function down(): void
    {
        // Tidak dikembalikan: nilai lama adalah data salah (part number di kolom
        // kelompok material), jadi rollback tidak memulihkannya.
    }
};
