<?php

namespace App\Services;

use App\Models\ConfigMaster;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Jadwal harian dari Production Plan.
 *
 * Satu WO di papan plan bisa punya beberapa baris mesin. Qty jadwal sebuah WO
 * diambil dari **nilai terbesar antar baris mesin**, bukan penjumlahan, karena
 * setiap mesin memproses qty yang sama.
 */
class DailyScheduleService
{
    /** Tanggal berjalan menurut zona waktu pabrik, bukan UTC server. */
    public function plantToday(): string
    {
        return now((string) ConfigMaster::getValue('SYSTEM', 'timezone', 'Asia/Jakarta'))->toDateString();
    }

    /**
     * Qty jadwal pada tanggal papan per WO: nilai terbesar antar baris mesin,
     * beserta `plan_date` baris yang menghasilkan nilai itu dan step routing
     * terawal. Kolom D/D+1/D+2 selalu relatif ke tanggal papan yang dipilih,
     * termasuk pada baris carry-over dari plan yang lebih lama.
     */
    public function scheduledQuantities(string $date, ?string $boardDate = null): QueryBuilder
    {
        $boardDate ??= $this->plantToday();
        $windowStart = Carbon::parse($boardDate)->subDays(2)->toDateString();
        $dayOffset = (int) Carbon::parse($boardDate)->diffInDays(Carbon::parse($date), false);
        $targetColumn = match ($dayOffset) {
            0 => 'ppi.target_d',
            1 => 'ppi.target_d1',
            2 => 'ppi.target_d2',
            default => null,
        };

        $effective = DB::table('production_plan_items as ppi')
            ->join('production_plans as pp', 'pp.id', '=', 'ppi.production_plan_id')
            ->join('work_orders as wo', 'wo.id', '=', 'ppi.work_order_id')
            ->whereNotNull('ppi.work_order_id')
            ->where(function ($query) use ($boardDate, $windowStart) {
                $query->whereBetween('pp.plan_date', [$windowStart, $boardDate])
                    ->orWhere(function ($carryOver) use ($windowStart) {
                        $carryOver->where('pp.plan_date', '<', $windowStart)
                            ->whereIn('wo.status', ['in_progress', 'completed']);
                    });
            })
            ->selectRaw('ppi.work_order_id, pp.plan_date, ppi.step_sequence')
            ->selectRaw($targetColumn === null ? 'NULL AS target' : $targetColumn.' AS target');

        if ($targetColumn === null) {
            $effective->whereRaw('1 = 0');
        }

        return DB::query()
            ->fromSub($effective, 'effective')
            ->groupBy('effective.work_order_id')
            ->selectRaw('effective.work_order_id')
            ->selectRaw('MAX(effective.target) AS planned_qty')
            ->selectRaw('MIN(effective.step_sequence) AS step_sequence')
            ->selectRaw('(ARRAY_AGG(effective.plan_date ORDER BY effective.target DESC NULLS LAST, effective.plan_date DESC))[1] AS plan_date')
            ->havingRaw('MAX(effective.target) > 0');
    }

    /** Total material yang sudah keluar per WO, dari dokumen issue yang berlaku. */
    public function issuedQuantities(): QueryBuilder
    {
        return DB::table('material_issue_items as mii')
            ->join('material_issues as mi', 'mi.id', '=', 'mii.material_issue_id')
            ->where('mi.status', 'posted')
            ->groupBy('mi.work_order_id')
            ->selectRaw('mi.work_order_id')
            ->selectRaw('SUM(mii.qty) AS issued_qty');
    }

    /**
     * WO yang sudah release dan punya baris plan, tetapi belum punya jadwal pada
     * tanggal berjalan — jaring pengaman agar pekerjaan tidak hilang senyap.
     *
     * @return Collection<int, WorkOrder>
     */
    public function waitingForPlan(string $date): Collection
    {
        return WorkOrder::query()
            ->with(['part:id,part_number,part_name'])
            ->where('work_orders.status', 'in_progress')
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('production_plan_items as ppi')
                ->whereColumn('ppi.work_order_id', 'work_orders.id'))
            ->whereNotIn('work_orders.id', $this->scheduledQuantities($date)->select('work_order_id'))
            ->orderBy('work_orders.wo_no')
            ->get(['work_orders.id', 'work_orders.wo_no', 'work_orders.part_id']);
    }
}
