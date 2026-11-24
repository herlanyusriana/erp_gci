<?php

namespace App\Services;

use App\Models\ConfigMaster;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Jadwal harian dari Production Plan.
 *
 * Production Plan menyimpan alokasi per tanggal kalender (`plan_date`) dengan
 * qty pada `target_d`. Satu WO bisa dijadwalkan di beberapa mesin pada hari
 * yang sama; qty jadwal harian WO diambil dari **nilai terbesar antar mesin**,
 * bukan penjumlahan, karena setiap mesin memproses qty yang sama.
 */
class DailyScheduleService
{
    /** Tanggal berjalan menurut zona waktu pabrik, bukan UTC server. */
    public function plantToday(): string
    {
        return now((string) ConfigMaster::getValue('SYSTEM', 'timezone', 'Asia/Jakarta'))->toDateString();
    }

    /**
     * Qty jadwal pada tanggal kalender `$date` per WO: nilai terbesar antar
     * baris mesin pada `plan_date = $date`, beserta step routing terawal.
     */
    public function scheduledQuantities(string $date, ?string $boardDate = null): QueryBuilder
    {
        return DB::table('production_plan_items as ppi')
            ->join('production_plans as pp', 'pp.id', '=', 'ppi.production_plan_id')
            ->whereNotNull('ppi.work_order_id')
            ->whereDate('pp.plan_date', $date)
            ->where('ppi.target_d', '>', 0)
            ->groupBy('ppi.work_order_id')
            ->selectRaw('ppi.work_order_id')
            ->selectRaw('MAX(ppi.target_d) AS planned_qty')
            ->selectRaw('MIN(ppi.step_sequence) AS step_sequence')
            ->selectRaw('MAX(pp.plan_date) AS plan_date');
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
     * WO yang sudah release (`in_progress`), tetapi belum dijadwalkan pada
     * tanggal berjalan — jaring pengaman agar pekerjaan tidak hilang senyap
     * di layar APK (Material Board & Issue Out).
     *
     * @return Collection<int, WorkOrder>
     */
    public function waitingForPlan(string $date): Collection
    {
        return WorkOrder::query()
            ->with(['part:id,part_number,part_name'])
            ->where('work_orders.status', 'in_progress')
            ->whereNotIn('work_orders.id', $this->scheduledQuantities($date)->select('work_order_id'))
            ->orderBy('work_orders.wo_no')
            ->get(['work_orders.id', 'work_orders.wo_no', 'work_orders.part_id']);
    }
}
