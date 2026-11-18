<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index jalur scan tag dan pelacakan output produksi.
 *
 * Query scan memakai LOWER(COALESCE(tag, '')) = ?, jadi btree pada kolom `tag`
 * mentah tidak terpakai; expression index di bawah identik dengan ekspresi
 * query (termasuk predikat soft delete). output_part_stock_id dipakai di dalam
 * loop dan join laporan WIP, sehingga diberi partial index.
 *
 * CONCURRENTLY dipakai agar aman bila tabel sudah berisi data di server trial;
 * karena itu migration ini tidak boleh dibungkus transaksi.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS part_stocks_tag_lower_active_idx ON part_stocks (LOWER(COALESCE(tag, ''))) WHERE deleted_at IS NULL");
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS production_results_output_part_stock_idx ON production_results (output_part_stock_id) WHERE output_part_stock_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS part_stocks_tag_lower_active_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS production_results_output_part_stock_idx');
    }
};
