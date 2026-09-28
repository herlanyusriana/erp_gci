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
     * Qty jadwal pada sebuah tanggal per WO: nilai terbesar antar baris mesin,
     * beserta `plan_date` baris yang menghasilkan nilai itu dan step routing
     * terawal.
     *
     * Untuk baris plan yang tanggalnya di luar jendela D..D+2, carry-over
     * dihitung sebagai sisa WO yang belum selesai (qty - hasil produksi),
     * bukan target kolom yang sudah kedaluwarsa.
     */
    public function scheduledQuantities(string $date): QueryBuilder
    {
        $dayBefore = Carbon::parse($date)->subDay()->toDateString();
        $windowStart = Carbon::parse($date)->subDays(2)->toDateString();

        // Baris normal: plan_date di dalam jendela D-2 .. D.
        // Target diambil dari kolom yang sesuai dengan offset harinya.
        $scheduled = DB::table('production_plan_items as ppi')
            ->join('production_plans as pp', 'pp.id', '=', 'ppi.production_plan_id')
            ->whereBetween('pp.plan_date', [$windowStart, $date])
            ->whereNotNull('ppi.work_order_id')
            ->selectRaw('ppi.work_order_id, pp.plan_date, ppi.step_sequence')
            ->selectRaw(
                'CASE WHEN pp.plan_date = ? THEN ppi.target_d WHEN pp.plan_date = ? THEN ppi.target_d1 ELSE ppi.target_d2 END AS target',
                [$date, $dayBefore],
            );

        // Carry-over: baris plan dengan plan_date < windowStart.
        // WO yang belum selesai tetap butuh material. Gunakan sisa WO
        // (qty - hasil produksi sampai dengan tanggal papan) sebagai jadwal.
        $carryOver = DB::table('production_plan_items as ppi')
            ->join('production_plans as pp', 'pp.id', '=', 'ppi.production_plan_id')
            ->join('work_orders as wo', 'wo.id', '=', 'ppi.work_order_id')
            ->where('pp.plan_date', '<', $windowStart)
            ->whereNotNull('ppi.work_order_id')
            ->selectRaw('ppi.work_order_id, pp.plan_date, ppi.step_sequence')
            ->selectRaw('GREATEST(0, wo.qty - COALESCE((SELECT SUM(pr.qty_good) FROM production_results pr WHERE pr.work_order_id = wo.id AND pr.result_date <= ?), 0)) AS target', [$date]);

        $all = $scheduled->unionAll($carryOver);

        return DB::query()
            ->fromSub($all, 'effective')
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
