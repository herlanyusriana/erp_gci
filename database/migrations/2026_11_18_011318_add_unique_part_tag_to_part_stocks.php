<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Satu tag hanya boleh muncul sekali per part pada stok aktif.
 *
 * Sebelum index dibuat, baris duplikat (part_id, tag) diperiksa. Bila masih ada,
 * migrasi berhenti dengan daftar baris yang harus dibersihkan operator — data
 * tidak dihapus otomatis. Index parsial dipakai karena baris yang sudah
 * di-soft-delete atau tanpa tag tidak ikut dibatasi.
 */
return new class extends Migration
{
    private const INDEX_NAME = 'part_stocks_part_id_tag_unique';

    public function up(): void
    {
        $duplicates = DB::table('part_stocks')
            ->select('part_id', 'tag')
            ->whereNull('deleted_at')
            ->whereNotNull('tag')
            ->groupBy('part_id', 'tag')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('part_id')
            ->orderBy('tag')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $list = $duplicates
                ->map(fn ($row) => 'part_id='.$row->part_id.' tag='.$row->tag)
                ->implode('; ');

            throw new RuntimeException(
                'Tidak dapat membuat unique index '.self::INDEX_NAME
                .': masih ada duplikat (part_id, tag) pada part_stocks. '
                .'Bersihkan baris berikut lalu jalankan ulang migrasi: '.$list
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX_NAME
            .' ON part_stocks (part_id, tag) WHERE deleted_at IS NULL AND tag IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX_NAME);
    }
};
