<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_plan_items', function (Blueprint $table) {
            // Proses baris (dari step WO) — biar papan tidak menebak dari nama part.
            $table->foreignId('process_id')->nullable()->after('machine_id')
                ->constrained('processes')->nullOnDelete();
        });

        // Backfill dari step WO yang cocok (work_order + mesin + parent).
        DB::statement(<<<'SQL'
            UPDATE production_plan_items AS pi
            SET process_id = sub.process_id
            FROM (
                SELECT wi.work_order_id, wi.machine_id, wi.parent_part_id, MIN(wi.process_id) AS process_id
                FROM work_order_items AS wi
                WHERE wi.parent_part_id IS NOT NULL
                GROUP BY wi.work_order_id, wi.machine_id, wi.parent_part_id
            ) AS sub
            WHERE pi.work_order_id = sub.work_order_id
              AND pi.machine_id IS NOT DISTINCT FROM sub.machine_id
              AND pi.wip_part_id = sub.parent_part_id
        SQL);
    }

    public function down(): void
    {
        Schema::table('production_plan_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('process_id');
        });
    }
};
