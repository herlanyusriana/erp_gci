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
            // Urutan step routing (dari WO) — dasar urutan grup mesin di papan.
            $table->unsignedInteger('step_sequence')->nullable()->after('sequence');
            $table->index(['production_plan_id', 'step_sequence']);
        });

        // Backfill baris lama dari step WO yang cocok (work_order + mesin + parent).
        DB::statement(<<<'SQL'
            UPDATE production_plan_items AS pi
            SET step_sequence = sub.seq
            FROM (
                SELECT wi.work_order_id, wi.machine_id, wi.parent_part_id, MIN(wi.sequence) AS seq
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
            $table->dropIndex(['production_plan_id', 'step_sequence']);
            $table->dropColumn('step_sequence');
        });
    }
};
