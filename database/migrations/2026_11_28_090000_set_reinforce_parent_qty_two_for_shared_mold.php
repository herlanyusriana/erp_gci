<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Pasangan FG REINFORCE,ASSEMBLY (Left & Right) yang dicetak bersamaan
     * dalam 1 mold / 1 stroke di TPL KUKIL (1 stroke menghasilkan 2 PCS).
     *
     * @var list<string>
     */
    private const SHARED_MOLD_REINFORCE_FGS = [
        'AHF73269912',
        'AHF73269903',
        'AHF73710119',
        'AHF73710120',
        'AHF73710110',
        'AHF73710109',
        'AHF73710102',
        'AHF73710101',
        'AHF73710107',
        'AHF73710108',
    ];

    public function up(): void
    {
        $fgPartIds = DB::table('parts')
            ->whereIn('part_number', self::SHARED_MOLD_REINFORCE_FGS)
            ->pluck('id')
            ->all();

        if ($fgPartIds === []) {
            return;
        }

        $bomIds = DB::table('boms')
            ->whereIn('part_id', $fgPartIds)
            ->pluck('id')
            ->all();

        if ($bomIds !== []) {
            DB::table('bom_items')
                ->whereIn('bom_id', $bomIds)
                ->where('sequence', 1)
                ->whereRaw("UPPER(COALESCE(uom_rm, '')) IN ('KGM', 'KG')")
                ->where('parent_qty', 1)
                ->update(['parent_qty' => 2]);
        }

        $plannedWoIds = DB::table('work_orders as wo')
            ->whereIn('wo.part_id', $fgPartIds)
            ->where('wo.status', 'planned')
            ->whereNull('wo.deleted_at')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('work_order_material_bookings as b')
                    ->whereColumn('b.work_order_id', 'wo.id');
            })
            ->pluck('wo.id')
            ->all();

        if ($plannedWoIds !== []) {
            DB::table('work_order_items')
                ->whereIn('work_order_id', $plannedWoIds)
                ->where('sequence', 1)
                ->whereRaw("UPPER(COALESCE(uom_rm, '')) IN ('KGM', 'KG')")
                ->where('parent_qty', 1)
                ->where('qty_consumed', 0)
                ->update([
                    'parent_qty' => 2,
                    'qty_required' => DB::raw('ROUND(qty_required / 2.0, 6)'),
                ]);
        }
    }

    public function down(): void
    {
        $fgPartIds = DB::table('parts')
            ->whereIn('part_number', self::SHARED_MOLD_REINFORCE_FGS)
            ->pluck('id')
            ->all();

        if ($fgPartIds === []) {
            return;
        }

        $bomIds = DB::table('boms')
            ->whereIn('part_id', $fgPartIds)
            ->pluck('id')
            ->all();

        if ($bomIds !== []) {
            DB::table('bom_items')
                ->whereIn('bom_id', $bomIds)
                ->where('sequence', 1)
                ->whereRaw("UPPER(COALESCE(uom_rm, '')) IN ('KGM', 'KG')")
                ->where('parent_qty', 2)
                ->update(['parent_qty' => 1]);
        }

        $plannedWoIds = DB::table('work_orders as wo')
            ->whereIn('wo.part_id', $fgPartIds)
            ->where('wo.status', 'planned')
            ->whereNull('wo.deleted_at')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('work_order_material_bookings as b')
                    ->whereColumn('b.work_order_id', 'wo.id');
            })
            ->pluck('wo.id')
            ->all();

        if ($plannedWoIds !== []) {
            DB::table('work_order_items')
                ->whereIn('work_order_id', $plannedWoIds)
                ->where('sequence', 1)
                ->whereRaw("UPPER(COALESCE(uom_rm, '')) IN ('KGM', 'KG')")
                ->where('parent_qty', 2)
                ->where('qty_consumed', 0)
                ->update([
                    'parent_qty' => 1,
                    'qty_required' => DB::raw('ROUND(qty_required * 2.0, 6)'),
                ]);
        }
    }
};
